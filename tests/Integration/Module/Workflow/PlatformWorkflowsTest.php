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
use Symfony\Component\Mime\Email;
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

    public function testANewMemberGetsAWelcomeEmailFromTheWorkspace(): void
    {
        $this->activate('Welcome', <<<'YAML'
            trigger: { on: member_added }
            steps:
              - key: welcome
                type: send_email
                to: [{ member: '{{ $trigger.member.email }}' }]
                subject: 'Welcome to {{ $trigger.workspace.name }}'
                body: 'Sign in at {{ $trigger.workspace.slug }}.'
            YAML);
        $users = self::getContainer()->get(UserManagerInterface::class);
        $users->createUser('nina@example.com', 'correct horse battery staple');
        self::getContainer()->get(PlatformWorkflows::class)->reset();

        $users->addToWorkspace('nina@example.com', $this->beta->id);
        $runs = self::getContainer()->get(PlatformWorkflows::class)->runPending();

        self::assertSame(RunLog::COMPLETED, $runs[0]->status, (string) $runs[0]->error);
        self::assertSame(['recipients' => 1, 'left_out' => 0, 'sent' => true], $runs[0]->steps[0]['output']);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('nina@example.com', $email->getTo()[0]->getAddress());
        self::assertSame($this->beta->name, $email->getFrom()[0]->getName());
        self::assertSame('Welcome to '.$this->beta->name, $email->getSubject());
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

    public function testARoleIsGivenToAMemberOnly(): void
    {
        // On a new member, a role for someone else: an email that is no member of beta.
        $this->activate('Friends', "trigger: { on: member_added }\nsteps: [{ key: friend, type: assign_role, email: friend@example.com, role: ROLE_CLERK }]");
        $users = self::getContainer()->get(UserManagerInterface::class);
        $users->createUser('nina@example.com', 'correct horse battery staple');
        self::getContainer()->get(PlatformWorkflows::class)->reset();
        $users->addToWorkspace('nina@example.com', $this->beta->id);

        $runs = self::getContainer()->get(PlatformWorkflows::class)->runPending();

        self::assertSame([RunLog::FAILED, 'beta: friend@example.com is no member of this workspace.'], [$runs[0]->status, $runs[0]->error]);
        // Nothing waits for whoever joins with that email later.
        self::assertSame([], $this->sessions()->within($this->beta, static fn (): array => self::getContainer()->get(WorkspaceRolesInterface::class)->rolesOf('friend@example.com')));
    }

    public function testARunThatBreaksIsKeptAsFailed(): void
    {
        $this->activate('Clerks', "trigger: { on: member_added }\nsteps: [{ key: all, type: filter, when: { field: \$trigger.workspace.slug, op: not_empty } }, { key: clerk, type: assign_role, email: '{{ \$trigger.member.email }}', role: ROLE_CLERK }]");
        $users = self::getContainer()->get(UserManagerInterface::class);
        $users->createUser('nina@example.com', 'correct horse battery staple');
        self::getContainer()->get(PlatformWorkflows::class)->reset();
        $users->addToWorkspace('nina@example.com', $this->beta->id);
        // Not a step's own failure: an error no step expects, as the database's are.
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("CREATE FUNCTION pg_temp.full_disk() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'The disk is full.'; END \$\$ LANGUAGE plpgsql");
        // The roles' tables are the workspace's own: named from inside it.
        $this->sessions()->within($this->beta, static fn (): mixed => $connection->executeStatement('CREATE TRIGGER full_disk BEFORE INSERT ON auth_assignment FOR EACH ROW EXECUTE FUNCTION pg_temp.full_disk()'));

        // Logged, and no run handed back.
        self::assertSame([], self::getContainer()->get(PlatformWorkflows::class)->runPending());

        // The console's pages show it, with the steps run before it broke.
        $run = $this->sessions()->asSystem(static fn (): array|false => $connection->fetchAssociative('SELECT r.status, r.error, (SELECT COUNT(*) FROM core.workflow_run_step s WHERE s.run_id = r.id) AS steps FROM core.workflow_run r WHERE r.workspace_id IS NULL'));
        self::assertIsArray($run);
        self::assertSame(['failed', 1], [$run['status'], $run['steps']]);
        self::assertIsString($run['error']);
        self::assertStringStartsWith('It broke: ', $run['error']);
        self::assertStringContainsString('The disk is full.', $run['error']);
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
            self::assertSame(['The platform\'s events are the system admin\'s: a workflow on them is defined in the system console.'], array_map(strval(...), $e->errors['trigger.on']));
        }

        // ...and the console none on a record's.
        $this->sessions()->asSystem(static function () use ($manager): void {
            $workflow = $manager->create('Records', null, "trigger: { on: created, object: person, when: after }\nsteps: [{ key: a, type: refuse, message: no }]");
            try {
                $manager->activate($workflow);
                self::fail('Refused.');
            } catch (InvalidWorkflowDefinition $e) {
                self::assertSame(['The system console\'s workflows run on the platform\'s events: workspace_created, member_added.'], array_map(strval(...), $e->errors['trigger.on']));
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
