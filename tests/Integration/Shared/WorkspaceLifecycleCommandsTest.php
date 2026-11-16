<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Infrastructure\Workspace\WorkspaceRegistry;
use App\Shared\Workspace\Workspace;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

/**
 * The workspace:* commands, phase 4 of the multi-tenancy plan.
 */
final class WorkspaceLifecycleCommandsTest extends KernelTestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    private ApplicationTester $console;
    private Connection $connection;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);
        $this->console = new ApplicationTester($application);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testAWorkspaceIsCreatedWithItsPermissionsAndItsFirstAdmin(): void
    {
        $this->command(['command' => 'workspace:create', 'slug' => 'globex', '--name' => 'Globex', '--admin-email' => 'Anna@Example.com', '--admin-password' => self::PASSWORD]);

        $globex = $this->workspace('globex');
        self::assertSame('Globex', $globex->name);
        self::assertNotFalse(
            $this->connection->fetchOne(\sprintf("SELECT 1 FROM %s.auth_item WHERE name = 'admin.metadata'", $globex->rbacSchema)),
            'access:sync created the workspace\'s permissions.',
        );
        self::assertSame(
            ['ROLE_SUPER_ADMIN'],
            $this->connection->fetchFirstColumn(\sprintf("SELECT item_name FROM %s.auth_assignment WHERE user_id = 'anna@example.com'", $globex->rbacSchema)),
        );
        self::assertSame(['anna@example.com'], $this->members($globex));
    }

    public function testAnExistingUserBecomesTheNewWorkspacesAdmin(): void
    {
        $this->command(['command' => 'user:create', 'email' => 'anna@example.com', '--password' => self::PASSWORD]);

        $this->command(['command' => 'workspace:create', 'slug' => 'globex', '--admin-email' => 'anna@example.com']);

        self::assertSame(['anna@example.com'], $this->members($this->workspace('globex')));
        self::assertSame(['anna@example.com'], $this->members($this->workspace('default')), 'Still a member where they were.');
    }

    public function testAWorkspaceIsSuspendedResumedAndListed(): void
    {
        $this->command(['command' => 'workspace:create', 'slug' => 'globex', '--name' => 'Globex']);

        $this->command(['command' => 'workspace:suspend', 'slug' => 'globex']);
        self::assertFalse($this->workspace('globex')->active);
        $this->command(['command' => 'workspace:list']);
        self::assertMatchesRegularExpression('/globex\s+Globex\s+suspended/', $this->console->getDisplay());

        $this->command(['command' => 'workspace:resume', 'slug' => 'globex']);
        self::assertTrue($this->workspace('globex')->active);
    }

    public function testAWorkspaceIsPutOnAPlan(): void
    {
        self::assertSame(1, $this->console->run(['command' => 'workspace:create', 'slug' => 'globex', '--plan' => 'gold'], ['interactive' => false]));
        self::assertStringContainsString('There is no plan "gold"', $this->console->getDisplay());
        self::assertNull(self::getContainer()->get(WorkspaceRegistry::class)->bySlug('globex'), 'Nothing created.');

        $this->command(['command' => 'workspace:create', 'slug' => 'globex', '--plan' => 'starter']);
        self::assertSame('starter', $this->workspace('globex')->plan);
        $this->command(['command' => 'workspace:plan', 'slug' => 'globex', 'plan' => 'business']);
        self::assertSame('business', $this->workspace('globex')->plan);
        $this->command(['command' => 'workspace:plan', 'slug' => 'default']);
        self::assertStringContainsString('default: unlimited (Unlimited), the default, no limit on workflow steps.', $this->console->getDisplay());
        $this->command(['command' => 'workspace:list']);
        self::assertMatchesRegularExpression('/globex\s+globex\s+active\s+business/', $this->console->getDisplay());
    }

    public function testAWorkspaceIsDeletedWithEverythingItHoldsOnceConfirmed(): void
    {
        $this->command(['command' => 'workspace:create', 'slug' => 'globex']);
        $this->command(['command' => 'metadata:object:create', 'name' => 'gadget', 'label-singular' => 'Gadget', 'label-plural' => 'Gadgets', '--workspace' => 'globex']);
        $globex = $this->workspace('globex');

        self::assertSame(1, $this->console->run(['command' => 'workspace:delete', 'slug' => 'globex'], ['interactive' => false]));
        self::assertStringContainsString('Not confirmed', $this->console->getDisplay());
        self::assertSame(1, $this->console->run(['command' => 'workspace:delete', 'slug' => 'default', '--confirm' => 'default'], ['interactive' => false]));
        self::assertStringContainsString('is the default workspace', $this->console->getDisplay());

        $this->command(['command' => 'workspace:delete', 'slug' => 'globex', '--confirm' => 'globex']);

        self::assertNull(self::getContainer()->get(WorkspaceRegistry::class)->bySlug('globex'));
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM core.metadata_object WHERE workspace_id = ?', [$globex->id]));
        self::assertSame([], $this->connection->fetchFirstColumn(
            'SELECT nspname FROM pg_namespace WHERE nspname IN (?, ?)',
            [$globex->dataSchema, $globex->rbacSchema],
        ));
    }

    /**
     * workspace:run starts processes of their own, which don't see this test's transaction: the
     * workspaces are created, and deleted, by processes too, committed.
     */
    public function testACommandRunsForEachWorkspaceInAProcessOfItsOwn(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $slugs = ['run-a-'.$suffix, 'run-b-'.$suffix];
        try {
            foreach ($slugs as $slug) {
                $this->process(['workspace:create', $slug]);
            }
            // A model in the first one only: each run must see its own workspace's.
            $this->process(['metadata:object:create', 'gadget', 'Gadget', 'Gadgets', '--workspace='.$slugs[0]]);

            $this->command(['command' => 'workspace:run', '--only' => $slugs, 'command-line' => ['metadata:list']]);

            $display = $this->console->getDisplay();
            self::assertStringContainsString('Done for 2 workspaces.', $display);
            [$first, $second] = explode($slugs[1], substr($display, (int) strpos($display, $slugs[0])), 2);
            self::assertStringContainsString('gadget', $first);
            self::assertStringNotContainsString('gadget', $second);
        } finally {
            foreach ($slugs as $slug) {
                $this->process(['workspace:delete', $slug, '--confirm='.$slug], mustSucceed: false);
            }
        }
    }

    public function testACommandRunForAnUnknownWorkspaceIsRefused(): void
    {
        self::assertSame(1, $this->console->run(['command' => 'workspace:run', '--only' => ['initech'], 'command-line' => ['metadata:list']], ['interactive' => false]));
        self::assertStringContainsString('There is no workspace "initech".', $this->console->getDisplay());
    }

    /**
     * A command in a process of its own: its changes are committed, outside this test's
     * transaction.
     *
     * @param list<string> $arguments
     */
    private function process(array $arguments, bool $mustSucceed = true): void
    {
        $process = new Process([\PHP_BINARY, self::getContainer()->getParameter('kernel.project_dir').'/bin/console', ...$arguments, '--env=test', '--no-interaction']);
        $process->run();
        if ($mustSucceed) {
            self::assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function command(array $input): void
    {
        self::assertSame(0, $this->console->run($input, ['interactive' => false]), $this->console->getDisplay());
    }

    private function workspace(string $slug): Workspace
    {
        $workspace = self::getContainer()->get(WorkspaceRegistry::class)->bySlug($slug);
        self::assertInstanceOf(Workspace::class, $workspace);

        return $workspace;
    }

    /**
     * @return list<mixed>
     */
    private function members(Workspace $workspace): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT u.email FROM core.workspace_membership m JOIN core.app_user u ON u.id = m.user_id WHERE m.workspace_id = ? ORDER BY 1',
            [$workspace->id],
        );
    }
}
