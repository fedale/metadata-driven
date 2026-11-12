<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Template\Domain\Repository\TemplateSourceRepository;
use App\Shared\Infrastructure\Tenant\TenantRegistry;
use App\Shared\Infrastructure\Tenant\TenantSessionSql;
use App\Shared\Infrastructure\Tenant\TenantSwitcher;
use App\Shared\Tenant\CurrentTenant;
use App\Shared\Tenant\Tenant;
use App\Tests\Support\CreatesTenants;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Uid\Uuid;

/**
 * Phase 2 of the multi-tenancy plan: two tenants, switched between in one process as a worker
 * does between requests, each with its own model, records, caches and RBAC tables.
 */
final class TwoTenantsTest extends KernelTestCase
{
    use CreatesTenants;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testEachTenantHasItsOwnModelAndRecords(): void
    {
        $container = self::getContainer();
        $default = $container->get(CurrentTenant::class);
        $defaultSchema = $default->dataSchema();
        $this->createPet(['name' => 'text'], ['name' => 'Rex']);

        $globex = self::createTenant($container, 'globex', 'Globex');
        self::useTenant($container, $globex);
        $this->createPet(['color' => 'text'], ['color' => 'Brown']);

        $pet = $container->get(MetadataRegistryInterface::class)->getObject('pet');
        self::assertArrayHasKey('color', $pet->fields);
        self::assertArrayNotHasKey('name', $pet->fields);
        self::assertSame(['Brown'], $this->connection->fetchFirstColumn(\sprintf('SELECT color FROM %s.c_pet', $globex->dataSchema)));

        $this->switchTo('default');
        $pet = $container->get(MetadataRegistryInterface::class)->getObject('pet');
        self::assertArrayHasKey('name', $pet->fields);
        self::assertArrayNotHasKey('color', $pet->fields);
        self::assertSame(['Rex'], $this->connection->fetchFirstColumn(\sprintf('SELECT name FROM %s.c_pet', $defaultSchema)));
    }

    public function testEachTenantHasItsOwnRolesAndPermissions(): void
    {
        $container = self::getContainer();
        $globex = self::createTenant($container, 'globex', 'Globex');
        $this->connection->executeStatement("INSERT INTO auth_item (name, type, created_at, updated_at) VALUES ('report.read', 'permission', now(), now())");

        self::useTenant($container, $globex);
        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM auth_item WHERE name = 'report.read'"));

        $this->switchTo('default');
        self::assertNotFalse($this->connection->fetchOne("SELECT 1 FROM auth_item WHERE name = 'report.read'"));
    }

    public function testTheLandlordsSessionReadsAndWritesNoTenantsRows(): void
    {
        $container = self::getContainer();
        $this->insertSource('acme');
        $container->get(TenantSwitcher::class)->landlord();

        self::assertFalse($container->get(CurrentTenant::class)->isSelected());
        self::assertSame([], $container->get(TemplateSourceRepository::class)->all());

        $this->connection->beginTransaction();
        try {
            // Not template_source: its rows may have no tenant (shared indexes).
            $this->connection->executeStatement(
                "INSERT INTO core.metadata_translation (id, locale, message_key, text, updated_at) VALUES (?, 'it', 'object.pet.singular', 'Animale', now())",
                [Uuid::v7()->toRfc4122()],
            );
            self::fail('A row was written in no tenant.');
        } catch (NotNullConstraintViolationException) {
            $this->addToAssertionCount(1);
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * The application's role is a superuser in the tests' database, which bypasses row-level
     * security: a role of the test's own, neither superuser nor BYPASSRLS, shows the policies.
     */
    public function testRowLevelSecurityKeepsATenantToItsRows(): void
    {
        $globex = self::createTenant(self::getContainer(), 'globex', 'Globex');
        $this->insertSource('acme');
        $this->insertSource('globex-catalog', $globex);

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('CREATE ROLE tenant_probe NOLOGIN');
            $this->connection->executeStatement('GRANT USAGE ON SCHEMA core TO tenant_probe');
            $this->connection->executeStatement('GRANT SELECT, INSERT ON core.template_source TO tenant_probe');
            $this->connection->executeStatement('SET ROLE tenant_probe');

            self::assertSame(['acme'], $this->sourceNames(), 'A tenant sees its own rows.');
            try {
                $this->connection->executeStatement('SAVEPOINT probe');
                $this->insertSource('stolen', $globex);
                self::fail('A tenant wrote another tenant\'s row.');
            } catch (DriverException $e) {
                self::assertStringContainsString('row-level security', $e->getMessage());
                $this->connection->executeStatement('ROLLBACK TO SAVEPOINT probe');
            }

            $this->connection->executeStatement(TenantSessionSql::none());
            self::assertSame([], $this->sourceNames(), 'A session that has no tenant, and is no landlord, sees nothing.');

            $this->connection->executeStatement(TenantSessionSql::landlord());
            self::assertSame(['acme', 'globex-catalog'], $this->sourceNames(), 'The landlord sees every tenant\'s rows.');
        } finally {
            // Undoes the role, the grants and the session's settings with them.
            $this->connection->rollBack();
        }
    }

    public function testACommandWorksForTheTenantOfItsOption(): void
    {
        $container = self::getContainer();
        $globex = self::createTenant($container, 'globex', 'Globex');
        self::useTenant($container, $globex);
        $container->get(MetadataManagerInterface::class)->createObject('gadget', 'Gadget', 'Gadgets');
        $this->switchTo('default');

        $application = new Application(self::$kernel ?? throw new \LogicException('No kernel.'));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run(['command' => 'metadata:list', '--tenant' => 'globex']));
        self::assertStringContainsString('gadget', $tester->getDisplay());

        self::assertSame(0, $tester->run(['command' => 'metadata:list']), 'Without --tenant, the default tenant.');
        self::assertStringNotContainsString('gadget', $tester->getDisplay());

        self::assertNotSame(0, $tester->run(['command' => 'metadata:list', '--tenant' => 'initech']));
        self::assertStringContainsString('There is no tenant "initech".', $tester->getDisplay());
    }

    /**
     * @param array<string, string> $fields name => type
     * @param array<string, string> $values
     */
    private function createPet(array $fields, array $values): void
    {
        $container = self::getContainer();
        $manager = $container->get(MetadataManagerInterface::class);
        $manager->createObject('pet', 'Pet', 'Pets');
        foreach ($fields as $name => $type) {
            $manager->createField('pet', $name, $type, ucfirst($name));
        }
        $container->get(RecordManagerInterface::class)->create(new RecordInput('pet', $values));
    }

    private function switchTo(string $slug): void
    {
        $tenant = self::getContainer()->get(TenantRegistry::class)->bySlug($slug);
        self::assertInstanceOf(Tenant::class, $tenant);
        self::useTenant(self::getContainer(), $tenant);
    }

    private function insertSource(string $name, ?Tenant $tenant = null): void
    {
        $this->connection->executeStatement(
            'INSERT INTO core.template_source (id, name, url, added_at, tenant_id) VALUES (?, ?, ?, now(), ?)',
            [Uuid::v7()->toRfc4122(), $name, \sprintf('https://%s.example.com/index.json', $name), $tenant?->id],
        );
    }

    /**
     * @return list<string>
     */
    private function sourceNames(): array
    {
        /** @var list<string> $names */
        $names = $this->connection->fetchFirstColumn('SELECT name FROM core.template_source ORDER BY name');

        return $names;
    }
}
