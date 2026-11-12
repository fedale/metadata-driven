<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Module\Template\Application\Source\TemplateSources;
use App\Module\Template\Domain\Exception\TemplateSourceFailed;
use App\Shared\Infrastructure\Tenant\TenantRegistry;
use App\Shared\Infrastructure\Tenant\TenantSessionSql;
use App\Shared\Infrastructure\Tenant\TenantSwitcher;
use App\Shared\Tenant\Tenant;
use App\Tests\Support\CreatesTenants;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Uid\Uuid;

/**
 * Phase 6 of the multi-tenancy plan: template indexes offered to every tenant by the operator.
 */
final class SharedTemplateSourcesTest extends KernelTestCase
{
    use CreatesTenants;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testEveryTenantSeesASharedIndexNextToItsOwn(): void
    {
        $container = self::getContainer();
        $globex = self::createTenant($container, 'globex', 'Globex');
        $this->insert('acme');                        // the default tenant's
        $this->insertShared('fedale-extra');

        self::assertSame(['acme' => false, 'fedale-extra' => true], $this->indexes());

        self::useTenant($container, $globex);
        self::assertSame(['fedale-extra' => true], $this->indexes());
    }

    public function testOnlyTheOperatorRemovesASharedIndex(): void
    {
        $this->insertShared('fedale-extra');

        try {
            self::getContainer()->get(TemplateSources::class)->remove('fedale-extra');
            self::fail('A tenant removed a shared index.');
        } catch (TemplateSourceFailed $e) {
            self::assertStringContainsString('is offered to every tenant', $e->getMessage());
        }

        $console = $this->console();
        self::assertSame(0, $console->run(['command' => 'tenant:template:repo', 'action' => 'remove', 'name' => 'fedale-extra'], ['interactive' => false]), $console->getDisplay());
        $this->useTenant(self::getContainer(), $this->tenant('default'));
        self::assertSame([], $this->indexes());
    }

    public function testNoIndexShadowsAnotherOfTheSameName(): void
    {
        $this->insert('acme');
        $this->insertShared('fedale-extra');
        $sources = self::getContainer()->get(TemplateSources::class);

        $this->assertNameTaken(static fn () => $sources->add('fedale-extra', 'https://templates.example.com/index.json', unsigned: true));

        // As the landlord: a tenant's name is taken too.
        self::getContainer()->get(TenantSwitcher::class)->landlord();
        $this->assertNameTaken(static fn () => $sources->add('acme', 'https://templates.example.com/index.json', unsigned: true));
    }

    /**
     * Under a role that is neither superuser nor BYPASSRLS, as in TwoTenantsTest.
     */
    public function testRowLevelSecurityLetsATenantReadASharedIndexButNotRemoveIt(): void
    {
        $this->insert('acme');
        $this->insertShared('fedale-extra');

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('CREATE ROLE tenant_probe NOLOGIN');
            $this->connection->executeStatement('GRANT USAGE ON SCHEMA core TO tenant_probe');
            $this->connection->executeStatement('GRANT SELECT, DELETE ON core.template_source TO tenant_probe');
            $this->connection->executeStatement('SET ROLE tenant_probe');

            self::assertSame(['acme', 'fedale-extra'], $this->connection->fetchFirstColumn('SELECT name FROM core.template_source ORDER BY name'));
            self::assertSame(0, $this->connection->executeStatement("DELETE FROM core.template_source WHERE name = 'fedale-extra'"));
            self::assertSame(1, $this->connection->executeStatement("DELETE FROM core.template_source WHERE name = 'acme'"));
        } finally {
            $this->connection->rollBack();
        }
    }

    private function assertNameTaken(callable $add): void
    {
        try {
            $add();
            self::fail('Two indexes got the same name.');
        } catch (TemplateSourceFailed $e) {
            self::assertStringContainsString('An index is already named', $e->getMessage());
        }
    }

    /**
     * @return array<string, bool> name => shared
     */
    private function indexes(): array
    {
        $indexes = [];
        foreach (self::getContainer()->get(TemplateSources::class)->indexes() as $index) {
            $indexes[$index['name']] = $index['shared'];
        }

        return $indexes;
    }

    private function insert(string $name): void
    {
        $this->connection->executeStatement(
            'INSERT INTO core.template_source (id, name, url, added_at) VALUES (?, ?, ?, now())',
            [Uuid::v7()->toRfc4122(), $name, \sprintf('https://%s.example.com/index.json', $name)],
        );
    }

    /**
     * As tenant:template:repo add does: in the landlord's session, where the row gets no tenant.
     */
    private function insertShared(string $name): void
    {
        $this->connection->executeStatement(TenantSessionSql::landlord());
        $this->insert($name);
        self::useTenant(self::getContainer(), $this->tenant('default'));
    }

    private function console(): ApplicationTester
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('No kernel.'));
        $application->setAutoExit(false);

        return new ApplicationTester($application);
    }

    private function tenant(string $slug): Tenant
    {
        $tenant = self::getContainer()->get(TenantRegistry::class)->bySlug($slug);
        self::assertInstanceOf(Tenant::class, $tenant);

        return $tenant;
    }
}
