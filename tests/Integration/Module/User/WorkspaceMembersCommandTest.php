<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\User;

use App\Tests\Support\CreatesWorkspaces;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * user:create makes a member of the command's workspace; workspace:member:* manage the others.
 */
final class WorkspaceMembersCommandTest extends KernelTestCase
{
    use CreatesWorkspaces;

    private ApplicationTester $console;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);
        $this->console = new ApplicationTester($application);
    }

    public function testAUserIsCreatedAsAMemberOfTheCommandsWorkspace(): void
    {
        self::createWorkspace(self::getContainer(), 'globex', 'Globex');

        $this->command(['command' => 'user:create', 'email' => 'anna@example.com', '--password' => 'correct horse battery staple']);
        $this->command(['command' => 'user:create', 'email' => 'bruno@example.com', '--password' => 'correct horse battery staple', '--workspace' => 'globex']);

        self::assertSame(['anna@example.com'], $this->members('default'));
        self::assertSame(['bruno@example.com'], $this->members('globex'));
    }

    public function testMembersAreAddedAndRemoved(): void
    {
        self::createWorkspace(self::getContainer(), 'globex', 'Globex');
        $this->command(['command' => 'user:create', 'email' => 'anna@example.com', '--password' => 'correct horse battery staple']);

        $this->command(['command' => 'workspace:member:add', 'workspace' => 'globex', 'email' => 'Anna@Example.com']);
        self::assertStringContainsString('may sign in to globex', $this->console->getDisplay());
        self::assertSame(['anna@example.com'], $this->members('globex'));

        $this->command(['command' => 'workspace:member:add', 'workspace' => 'globex', 'email' => 'anna@example.com']);
        self::assertStringContainsString('a member of globex already', $this->console->getDisplay());

        $this->command(['command' => 'workspace:member:remove', 'workspace' => 'default', 'email' => 'anna@example.com']);
        self::assertSame([], $this->members('default'));
        self::assertSame(['anna@example.com'], $this->members('globex'));
    }

    public function testAnUnknownWorkspaceOrUserIsRefused(): void
    {
        self::assertSame(1, $this->console->run(['command' => 'workspace:member:add', 'workspace' => 'initech', 'email' => 'anna@example.com']));
        self::assertStringContainsString('There is no workspace "initech".', $this->console->getDisplay());

        self::assertSame(1, $this->console->run(['command' => 'workspace:member:add', 'workspace' => 'default', 'email' => 'nobody@example.com']));
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
    private function members(string $workspace): array
    {
        $this->command(['command' => 'workspace:member:list', 'workspace' => $workspace]);
        preg_match_all('/\S+@\S+/', $this->console->getDisplay(), $matches);

        return $matches[0];
    }
}
