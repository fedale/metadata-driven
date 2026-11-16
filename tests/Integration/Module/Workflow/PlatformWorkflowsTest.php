<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Workflow;

use App\Module\Access\Contract\AccessSetupInterface;
use App\Module\Access\Contract\WorkspaceRolesInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Package\Application\Source\PackageSources;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\PlatformWorkflows;
use App\Module\Workflow\Application\RunQueries;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition;
use App\Module\Workflow\Domain\Repository\WorkflowRepository;
use App\Shared\Workspace\Workspace;
use App\Shared\Workspace\WorkspaceCreated;
use App\Shared\Workspace\WorkspaceSessions;
use App\Tests\Support\CreatesWorkspaces;
use App\Tests\Support\FakePackageServer;
use App\Tests\Support\PublishesPackages;
use App\Tests\Support\RemovesDirectories;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The system admin's workflows: on a workspace created, a package installed in it; on a member
 * added, a role given. They are the system's: no workspace sees them, or their runs.
 */
final class PlatformWorkflowsTest extends KernelTestCase
{
    use CreatesWorkspaces;
    use PublishesPackages;
    use RemovesDirectories;

    private string $directory;

    private Workspace $beta;

    protected function setUp(): void
    {
        FakePackageServer::reset();
        $this->directory = sys_get_temp_dir().'/md-platform-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->publish(['1.0.0' => $this->archive('1.0.0')]);

        $container = self::getContainer();
        $container->get(UserManagerInterface::class)->createUser('olga@example.com', 'correct horse battery staple');
        $container->get(UserManagerInterface::class)->setSystemAdmin('olga@example.com', true);
        $user = $container->get(UserProvider::class)->loadUserByIdentifier('olga@example.com');
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $this->beta = self::createWorkspace($container, 'beta', 'Beta');
        $this->sessions()->within($this->beta, static function () use ($container): void {
            $container->get(AccessSetupInterface::class)->syncAll();
            $container->get(WorkspaceRolesInterface::class)->createRole('ROLE_CLERK', null, []);
        });
        $this->sessions()->asSystem(static fn () => $container->get(PackageSources::class)->add('acme', self::INDEX, unsigned: true));
        // Olga, made a member of the tests' workspace: not an event of these tests.
        $container->get(PlatformWorkflows::class)->reset();
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
        parent::tearDown();
    }

    public function testANewWorkspaceGetsAPackage(): void
    {
        $this->activate('Shop everywhere', "trigger: { on: workspace_created }\nsteps: [{ key: app, type: install_package, package: acme/shop, repo: acme }]");

        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new WorkspaceCreated($this->beta));
        $runs = self::getContainer()->get(PlatformWorkflows::class)->runPending();

        self::assertCount(1, $runs);
        self::assertSame(RunLog::COMPLETED, $runs[0]->status, (string) $runs[0]->error);
        self::assertSame(['installed' => ['acme/shop 1.0.0']], $runs[0]->steps[0]['output']);
        self::assertTrue($this->sessions()->within($this->beta, static fn (): bool => self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('tag')));
        // The system's: no workspace sees the workflow or its run.
        self::assertSame([], self::getContainer()->get(WorkflowRepository::class)->all());
        self::assertSame([], self::getContainer()->get(RunQueries::class)->summary());
        self::assertEquals(1, $this->sessions()->asSystem(static fn (): mixed => self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM core.workflow_run WHERE workspace_id IS NULL')));
    }

    public function testANewMemberGetsARoleWhereTheWorkflowSays(): void
    {
        $this->activate('Clerks of beta', <<<'YAML'
            trigger: { on: member_added }
            steps:
              - { key: beta_only, type: filter, when: { field: $trigger.workspace.slug, op: eq, value: beta } }
              - { key: clerk, type: assign_role, email: '{{ $trigger.member.email }}', role: ROLE_CLERK }
            YAML);
        $users = self::getContainer()->get(UserManagerInterface::class);
        $users->createUser('nina@example.com', 'correct horse battery staple');
        // Made a member of the tests' workspace too: its event is not this test's.
        self::getContainer()->get(PlatformWorkflows::class)->reset();

        $users->addToWorkspace('nina@example.com', $this->beta->id);
        $runs = self::getContainer()->get(PlatformWorkflows::class)->runPending();

        self::assertSame(RunLog::COMPLETED, $runs[0]->status, (string) $runs[0]->error);
        self::assertSame(['given' => true], $runs[0]->steps[1]['output']);
        self::assertSame(['ROLE_CLERK'], $this->sessions()->within($this->beta, static fn (): array => self::getContainer()->get(WorkspaceRolesInterface::class)->rolesOf('nina@example.com')));
        // A member already: no event, no run.
        $users->addToWorkspace('nina@example.com', $this->beta->id);
        self::assertSame([], self::getContainer()->get(PlatformWorkflows::class)->runPending());
    }

    public function testAStepThatFailsFailsTheRun(): void
    {
        $this->activate('Bosses', "trigger: { on: member_added }\nsteps: [{ key: boss, type: assign_role, email: '{{ \$trigger.member.email }}', role: ROLE_BOSS }]");
        $users = self::getContainer()->get(UserManagerInterface::class);
        $users->createUser('nina@example.com', 'correct horse battery staple');
        // Made a member of the tests' workspace too: its event is not this test's.
        self::getContainer()->get(PlatformWorkflows::class)->reset();
        $users->addToWorkspace('nina@example.com', $this->beta->id);

        $runs = self::getContainer()->get(PlatformWorkflows::class)->runPending();

        self::assertSame([RunLog::FAILED, 'beta: "ROLE_BOSS" is no role of this workspace.'], [$runs[0]->status, $runs[0]->error]);
    }

    public function testEachSideDefinesItsOwn(): void
    {
        // A workspace defines no workflow on the platform's events...
        $manager = self::getContainer()->get(WorkflowManager::class);
        $workflow = $manager->create('Sneaky', null, "trigger: { on: workspace_created }\nsteps: [{ key: app, type: install_package, package: acme/shop }]");
        try {
            $manager->activate($workflow);
            self::fail('Refused.');
        } catch (InvalidWorkflowDefinition $e) {
            self::assertSame(['The platform\'s events are the system admin\'s: a workflow on them is defined in the system console.'], $e->errors['trigger.on']);
        }

        // ...and the console none on a record's.
        $this->sessions()->asSystem(static function () use ($manager): void {
            $workflow = $manager->create('Records', null, "trigger: { on: created, object: person, when: after }\nsteps: [{ key: a, type: refuse, message: no }]");
            try {
                $manager->activate($workflow);
                self::fail('Refused.');
            } catch (InvalidWorkflowDefinition $e) {
                self::assertSame(['The system console\'s workflows run on the platform\'s events: workspace_created, member_added.'], $e->errors['trigger.on']);
            }
        });
    }

    private function activate(string $name, string $yaml): void
    {
        $manager = self::getContainer()->get(WorkflowManager::class);
        $this->sessions()->asSystem(static function () use ($manager, $name, $yaml): void {
            $manager->activate($manager->create($name, null, $yaml));
        });
    }

    private function sessions(): WorkspaceSessions
    {
        return self::getContainer()->get(WorkspaceSessions::class);
    }
}
