<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\User;

use App\Module\User\Contract\Exception\UserException;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Domain\Repository\UserRepository;
use App\Module\User\Infrastructure\Security\SecurityUser;
use App\Module\User\Infrastructure\Security\UserProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

final class UserManagerTest extends KernelTestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    public function testCreatesAUserWithItsProfile(): void
    {
        self::getContainer()->get(UserManagerInterface::class)->createUser('  Ada@Example.COM ', self::PASSWORD, 'Ada', 'Lovelace');

        $user = self::getContainer()->get(UserRepository::class)->findByEmail('ada@example.com');
        self::assertNotNull($user);
        self::assertSame('ada@example.com', $user->getEmail());
        self::assertSame('Ada Lovelace', $user->getDisplayName());
        self::assertSame('Ada', $user->getProfile()?->getFirstName());
        self::assertNotSame(self::PASSWORD, $user->getPasswordHash());

        $securityUser = self::getContainer()->get(UserProvider::class)->loadUserByIdentifier('ADA@example.com');
        self::assertSame(['ROLE_USER'], $securityUser->getRoles());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($securityUser, self::PASSWORD));
    }

    public function testRefusesInvalidInput(): void
    {
        $users = self::getContainer()->get(UserManagerInterface::class);
        $users->createUser('ada@example.com', self::PASSWORD);

        foreach ([
            ['not-an-email', self::PASSWORD, 'not a valid email'],
            ['ADA@example.com', self::PASSWORD, 'already exists'],
            ['bob@example.com', 'short', 'at least 12 characters'],
        ] as [$email, $password, $message]) {
            try {
                $users->createUser($email, $password);
                self::fail($email.' accepted');
            } catch (UserException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testUnknownUsersAreNotFound(): void
    {
        $this->expectException(UserNotFoundException::class);

        self::getContainer()->get(UserProvider::class)->loadUserByIdentifier('ghost@example.com');
    }

    public function testCreatesAUserFromTheConsole(): void
    {
        $command = new CommandTester((new Application(self::bootKernel()))->find('user:create'));
        $command->execute(['email' => 'grace@example.com', '--first-name' => 'Grace', '--last-name' => 'Hopper', '--password' => self::PASSWORD]);

        $command->assertCommandIsSuccessful();
        self::assertStringContainsString('rbac:assign grace@example.com <role>', $command->getDisplay());
        $user = self::getContainer()->get(UserProvider::class)->loadUserByIdentifier('grace@example.com');
        self::assertInstanceOf(SecurityUser::class, $user);
        self::assertSame('Grace Hopper', $user->getDisplayName());
    }
}
