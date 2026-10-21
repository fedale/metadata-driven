<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Access\Application\PermissionSynchronizer;
use App\Module\Access\Contract\Permissions;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Creates a user, grants them RBAC items, and signs them in on the test client.
 */
trait SignsIn
{
    public const string PASSWORD = 'correct horse battery staple';

    /**
     * @param list<string> $items roles or permissions to assign; they must exist
     */
    private static function signIn(KernelBrowser $client, string $email, array $items = []): void
    {
        $container = self::getContainer();
        self::createUser($email);
        $container->get(PermissionSynchronizer::class)->syncAll();
        foreach ($items as $item) {
            $container->get(RbacManagerInterface::class)->assign($email, $item);
        }

        $client->loginUser($container->get(UserProvider::class)->loadUserByIdentifier($email));
    }

    private static function signInAsSuperAdmin(KernelBrowser $client): void
    {
        self::signIn($client, 'admin@example.com', [Permissions::SUPER_ADMIN_ROLE]);
    }

    private static function createUser(string $email): void
    {
        self::getContainer()->get(UserManagerInterface::class)->createUser($email, self::PASSWORD, 'Ada', 'Lovelace');
    }
}
