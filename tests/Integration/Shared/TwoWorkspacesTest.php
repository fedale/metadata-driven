<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Template\Domain\Repository\TemplateSourceRepository;
use App\Shared\Infrastructure\Workspace\WorkspaceRegistry;
use App\Shared\Infrastructure\Workspace\WorkspaceSessionSql;
use App\Shared\Infrastructure\Workspace\WorkspaceSwitcher;
use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\Workspace;
use App\Tests\Support\CreatesWorkspaces;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Fedale\RbacBundle\Contract\AssignmentStorageInterface;
use Fedale\RbacBundle\Contract\ItemStorageInterface;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Fedale\RbacBundle\Contract\TenantContextInterface;
use Fedale\RbacBundle\Enum\AuthItemType;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Uid\Uuid;

/**
 * Phase 2 of the multi-tenancy plan: two workspaces, switched between in one process as a worker
 * does between requests, each with its own model, records, caches and RBAC tables.
 */
final class TwoWorkspacesTest extends KernelTestCase
{
    use CreatesWorkspaces;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testEachWorkspaceHasItsOwnModelAndRecords(): void
    {
        $container = self::getContainer();
        $default = $container->get(CurrentWorkspace::class);
        $defaultSchema = $default->dataSchema();
        $this->createPet(['name' => 'text'], ['name' => 'Rex']);

        $globex = self::createWorkspace($container, 'globex', 'Globex');
        self::useWorkspace($container, $globex);
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

    public function testEachWorkspaceHasItsOwnRolesAndPermissions(): void
    {
        $container = self::getContainer();
        $globex = self::createWorkspace($container, 'globex', 'Globex');
        $this->connection->executeStatement("INSERT INTO auth_item (name, type, created_at, updated_at) VALUES ('report.read', 'permission', now(), now())");

        self::useWorkspace($container, $globex);
        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM auth_item WHERE name = 'report.read'"));

        $this->switchTo('default');
        self::assertNotFalse($this->connection->fetchOne("SELECT 1 FROM auth_item WHERE name = 'report.read'"));
    }

    /**
     * fedale/rbac-bundle reads every workspace's assignments in its 'global' workspace (tables per
     * workspace): its memo of them must still tell the workspaces apart, through RbacWorkspaceContext.
     */
    public function testAUsersRolesFollowTheWorkspaceInOneProcess(): void
    {
        $container = self::getContainer();
        $rbac = $container->get(RbacManagerInterface::class);
        $assignments = $container->get(AssignmentStorageInterface::class);
        $rbac->addItem('ROLE_REPORTER', AuthItemType::ROLE);
        $rbac->assign('anna@example.com', 'ROLE_REPORTER');
        self::assertSame(['ROLE_REPORTER'], $assignments->getAssignments('anna@example.com'));

        $globex = self::createWorkspace($container, 'globex', 'Globex');
        self::useWorkspace($container, $globex);
        self::assertSame($globex->id, $container->get(TenantContextInterface::class)->getTenantId());
        self::assertSame([], $assignments->getAssignments('anna@example.com'));
        self::assertNull($container->get(ItemStorageInterface::class)->getItem('ROLE_REPORTER'));

        $this->switchTo('default');
        self::assertSame(['ROLE_REPORTER'], $assignments->getAssignments('anna@example.com'));
        self::assertNotNull($container->get(ItemStorageInterface::class)->getItem('ROLE_REPORTER'));

        $container->get(WorkspaceSwitcher::class)->system();
        self::assertSame(TenantContextInterface::GLOBAL, $container->get(TenantContextInterface::class)->getTenantId());
        self::assertSame([], $assignments->getAssignments('anna@example.com'));
    }

    public function testTheSystemSessionReadsAndWritesNoWorkspacesRows(): void
    {
        $container = self::getContainer();
        $this->insertSource('acme');
        $container->get(WorkspaceSwitcher::class)->system();

        self::assertFalse($container->get(CurrentWorkspace::class)->isSelected());
        self::assertSame([], $container->get(TemplateSourceRepository::class)->all());

        $this->connection->beginTransaction();
        try {
            // Not template_source: its rows may have no workspace (shared indexes).
            $this->connection->executeStatement(
                "INSERT INTO core.metadata_translation (id, locale, message_key, text, updated_at) VALUES (?, 'it', 'object.pet.singular', 'Animale', now())",
                [Uuid::v7()->toRfc4122()],
            );
            self::fail('A row was written in no workspace.');
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
    public function testRowLevelSecurityKeepsAWorkspaceToItsRows(): void
    {
        $globex = self::createWorkspace(self::getContainer(), 'globex', 'Globex');
        $this->insertSource('acme');
        $this->insertSource('globex-catalog', $globex);

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('CREATE ROLE workspace_probe NOLOGIN');
            $this->connection->executeStatement('GRANT USAGE ON SCHEMA core TO workspace_probe');
            $this->connection->executeStatement('GRANT SELECT, INSERT ON core.template_source TO workspace_probe');
            $this->connection->executeStatement('SET ROLE workspace_probe');

            self::assertSame(['acme'], $this->sourceNames(), 'A workspace sees its own rows.');
            try {
                $this->connection->executeStatement('SAVEPOINT probe');
                $this->insertSource('stolen', $globex);
                self::fail('A workspace wrote another workspace\'s row.');
            } catch (DriverException $e) {
                self::assertStringContainsString('row-level security', $e->getMessage());
                $this->connection->executeStatement('ROLLBACK TO SAVEPOINT probe');
            }

            $this->connection->executeStatement(WorkspaceSessionSql::none());
            self::assertSame([], $this->sourceNames(), 'A session that has no workspace, and is not the system, sees nothing.');

            $this->connection->executeStatement(WorkspaceSessionSql::system());
            self::assertSame(['acme', 'globex-catalog'], $this->sourceNames(), 'the system sees every workspace\'s rows.');
        } finally {
            // Undoes the role, the grants and the session's settings with them.
            $this->connection->rollBack();
        }
    }

    public function testACommandWorksForTheWorkspaceOfItsOption(): void
    {
        $container = self::getContainer();
        $globex = self::createWorkspace($container, 'globex', 'Globex');
        self::useWorkspace($container, $globex);
        $container->get(MetadataManagerInterface::class)->createObject('gadget', 'Gadget', 'Gadgets');
        $this->switchTo('default');

        $application = new Application(self::$kernel ?? throw new \LogicException('No kernel.'));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run(['command' => 'metadata:list', '--workspace' => 'globex']));
        self::assertStringContainsString('gadget', $tester->getDisplay());

        self::assertSame(0, $tester->run(['command' => 'metadata:list']), 'Without --workspace, the default workspace.');
        self::assertStringNotContainsString('gadget', $tester->getDisplay());

        self::assertNotSame(0, $tester->run(['command' => 'metadata:list', '--workspace' => 'initech']));
        self::assertStringContainsString('There is no workspace "initech".', $tester->getDisplay());
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
        $workspace = self::getContainer()->get(WorkspaceRegistry::class)->bySlug($slug);
        self::assertInstanceOf(Workspace::class, $workspace);
        self::useWorkspace(self::getContainer(), $workspace);
    }

    private function insertSource(string $name, ?Workspace $workspace = null): void
    {
        $this->connection->executeStatement(
            'INSERT INTO core.template_source (id, name, url, added_at, workspace_id) VALUES (?, ?, ?, now(), ?)',
            [Uuid::v7()->toRfc4122(), $name, \sprintf('https://%s.example.com/index.json', $name), $workspace?->id],
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
