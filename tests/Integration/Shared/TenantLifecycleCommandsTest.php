<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Infrastructure\Tenant\TenantRegistry;
use App\Shared\Tenant\Tenant;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;

/**
 * The tenant:* commands, phase 4 of the multi-tenancy plan.
 */
final class TenantLifecycleCommandsTest extends KernelTestCase
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

    public function testATenantIsCreatedWithItsPermissionsAndItsFirstAdmin(): void
    {
        $this->command(['command' => 'tenant:create', 'slug' => 'globex', '--name' => 'Globex', '--admin-email' => 'Anna@Example.com', '--admin-password' => self::PASSWORD]);

        $globex = $this->tenant('globex');
        self::assertSame('Globex', $globex->name);
        self::assertNotFalse(
            $this->connection->fetchOne(\sprintf("SELECT 1 FROM %s.auth_item WHERE name = 'admin.metadata'", $globex->rbacSchema)),
            'access:sync created the tenant\'s permissions.',
        );
        self::assertSame(
            ['ROLE_SUPER_ADMIN'],
            $this->connection->fetchFirstColumn(\sprintf("SELECT item_name FROM %s.auth_assignment WHERE user_id = 'anna@example.com'", $globex->rbacSchema)),
        );
        self::assertSame(['anna@example.com'], $this->members($globex));
    }

    public function testAnExistingUserBecomesTheNewTenantsAdmin(): void
    {
        $this->command(['command' => 'user:create', 'email' => 'anna@example.com', '--password' => self::PASSWORD]);

        $this->command(['command' => 'tenant:create', 'slug' => 'globex', '--admin-email' => 'anna@example.com']);

        self::assertSame(['anna@example.com'], $this->members($this->tenant('globex')));
        self::assertSame(['anna@example.com'], $this->members($this->tenant('default')), 'Still a member where they were.');
    }

    public function testATenantIsSuspendedResumedAndListed(): void
    {
        $this->command(['command' => 'tenant:create', 'slug' => 'globex', '--name' => 'Globex']);

        $this->command(['command' => 'tenant:suspend', 'slug' => 'globex']);
        self::assertFalse($this->tenant('globex')->active);
        $this->command(['command' => 'tenant:list']);
        self::assertMatchesRegularExpression('/globex\s+Globex\s+suspended/', $this->console->getDisplay());

        $this->command(['command' => 'tenant:resume', 'slug' => 'globex']);
        self::assertTrue($this->tenant('globex')->active);
    }

    public function testATenantIsDeletedWithEverythingItHoldsOnceConfirmed(): void
    {
        $this->command(['command' => 'tenant:create', 'slug' => 'globex']);
        $this->command(['command' => 'metadata:object:create', 'name' => 'gadget', 'label-singular' => 'Gadget', 'label-plural' => 'Gadgets', '--tenant' => 'globex']);
        $globex = $this->tenant('globex');

        self::assertSame(1, $this->console->run(['command' => 'tenant:delete', 'slug' => 'globex'], ['interactive' => false]));
        self::assertStringContainsString('Not confirmed', $this->console->getDisplay());
        self::assertSame(1, $this->console->run(['command' => 'tenant:delete', 'slug' => 'default', '--confirm' => 'default'], ['interactive' => false]));
        self::assertStringContainsString('is the default tenant', $this->console->getDisplay());

        $this->command(['command' => 'tenant:delete', 'slug' => 'globex', '--confirm' => 'globex']);

        self::assertNull(self::getContainer()->get(TenantRegistry::class)->bySlug('globex'));
        self::assertFalse($this->connection->fetchOne('SELECT 1 FROM core.metadata_object WHERE tenant_id = ?', [$globex->id]));
        self::assertSame([], $this->connection->fetchFirstColumn(
            'SELECT nspname FROM pg_namespace WHERE nspname IN (?, ?)',
            [$globex->dataSchema, $globex->rbacSchema],
        ));
    }

    /**
     * tenant:run starts processes of their own, which don't see this test's transaction: the
     * tenants are created, and deleted, by processes too, committed.
     */
    public function testACommandRunsForEachTenantInAProcessOfItsOwn(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $slugs = ['run-a-'.$suffix, 'run-b-'.$suffix];
        try {
            foreach ($slugs as $slug) {
                $this->process(['tenant:create', $slug]);
            }
            // A model in the first one only: each run must see its own tenant's.
            $this->process(['metadata:object:create', 'gadget', 'Gadget', 'Gadgets', '--tenant='.$slugs[0]]);

            $this->command(['command' => 'tenant:run', '--only' => $slugs, 'command-line' => ['metadata:list']]);

            $display = $this->console->getDisplay();
            self::assertStringContainsString('Done for 2 tenants.', $display);
            [$first, $second] = explode($slugs[1], substr($display, (int) strpos($display, $slugs[0])), 2);
            self::assertStringContainsString('gadget', $first);
            self::assertStringNotContainsString('gadget', $second);
        } finally {
            foreach ($slugs as $slug) {
                $this->process(['tenant:delete', $slug, '--confirm='.$slug], mustSucceed: false);
            }
        }
    }

    public function testACommandRunForAnUnknownTenantIsRefused(): void
    {
        self::assertSame(1, $this->console->run(['command' => 'tenant:run', '--only' => ['initech'], 'command-line' => ['metadata:list']], ['interactive' => false]));
        self::assertStringContainsString('There is no tenant "initech".', $this->console->getDisplay());
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

    private function tenant(string $slug): Tenant
    {
        $tenant = self::getContainer()->get(TenantRegistry::class)->bySlug($slug);
        self::assertInstanceOf(Tenant::class, $tenant);

        return $tenant;
    }

    /**
     * @return list<mixed>
     */
    private function members(Tenant $tenant): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT u.email FROM core.tenant_membership m JOIN core.app_user u ON u.id = m.user_id WHERE m.tenant_id = ? ORDER BY 1',
            [$tenant->id],
        );
    }
}
