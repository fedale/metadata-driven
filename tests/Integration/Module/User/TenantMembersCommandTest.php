<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\User;

use App\Tests\Support\CreatesTenants;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * user:create makes a member of the command's tenant; tenant:member:* manage the others.
 */
final class TenantMembersCommandTest extends KernelTestCase
{
    use CreatesTenants;

    private ApplicationTester $console;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);
        $this->console = new ApplicationTester($application);
    }

    public function testAUserIsCreatedAsAMemberOfTheCommandsTenant(): void
    {
        self::createTenant(self::getContainer(), 'globex', 'Globex');

        $this->command(['command' => 'user:create', 'email' => 'anna@example.com', '--password' => 'correct horse battery staple']);
        $this->command(['command' => 'user:create', 'email' => 'bruno@example.com', '--password' => 'correct horse battery staple', '--tenant' => 'globex']);

        self::assertSame(['anna@example.com'], $this->members('default'));
        self::assertSame(['bruno@example.com'], $this->members('globex'));
    }

    public function testMembersAreAddedAndRemoved(): void
    {
        self::createTenant(self::getContainer(), 'globex', 'Globex');
        $this->command(['command' => 'user:create', 'email' => 'anna@example.com', '--password' => 'correct horse battery staple']);

        $this->command(['command' => 'tenant:member:add', 'tenant' => 'globex', 'email' => 'Anna@Example.com']);
        self::assertStringContainsString('may sign in to globex', $this->console->getDisplay());
        self::assertSame(['anna@example.com'], $this->members('globex'));

        $this->command(['command' => 'tenant:member:add', 'tenant' => 'globex', 'email' => 'anna@example.com']);
        self::assertStringContainsString('a member of globex already', $this->console->getDisplay());

        $this->command(['command' => 'tenant:member:remove', 'tenant' => 'default', 'email' => 'anna@example.com']);
        self::assertSame([], $this->members('default'));
        self::assertSame(['anna@example.com'], $this->members('globex'));
    }

    public function testAnUnknownTenantOrUserIsRefused(): void
    {
        self::assertSame(1, $this->console->run(['command' => 'tenant:member:add', 'tenant' => 'initech', 'email' => 'anna@example.com']));
        self::assertStringContainsString('There is no tenant "initech".', $this->console->getDisplay());

        self::assertSame(1, $this->console->run(['command' => 'tenant:member:add', 'tenant' => 'default', 'email' => 'nobody@example.com']));
    }

    /**
     * @param array<string, string> $input
     */
    private function command(array $input): void
    {
        self::assertSame(0, $this->console->run($input), $this->console->getDisplay());
    }

    /**
     * @return list<string>
     */
    private function members(string $tenant): array
    {
        $this->command(['command' => 'tenant:member:list', 'tenant' => $tenant]);
        preg_match_all('/\S+@\S+/', $this->console->getDisplay(), $matches);

        return $matches[0];
    }
}
