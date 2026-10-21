<?php

declare(strict_types=1);

namespace App\Module\User\Infrastructure\Security;

use App\Module\User\Domain\Repository\UserRepository;
use Fedale\RbacBundle\Security\AssignmentRolesProvider;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads active users by email, with the roles assigned to them in the RBAC tables (read on
 * every request, so a new role applies without signing in again).
 *
 * @implements UserProviderInterface<SecurityUser>
 */
final class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AssignmentRolesProvider $roles,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): SecurityUser
    {
        $user = $this->users->findByEmail($identifier);
        if (null === $user || !$user->isActive()) {
            // Same error for unknown and deactivated users: no account enumeration.
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return SecurityUser::fromUser($user, array_values($this->roles->getRolesFor($user->getEmail())));
    }

    public function refreshUser(UserInterface $user): SecurityUser
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException(\sprintf('Unsupported user class "%s".', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return SecurityUser::class === $class || is_subclass_of($class, SecurityUser::class);
    }

    /**
     * Rehashes the password on login when the hashing algorithm or its cost changed.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        $domainUser = $this->users->findByEmail($user instanceof SecurityUser ? $user->getUserIdentifier() : '');
        if (null !== $domainUser) {
            $domainUser->changePasswordHash($newHashedPassword);
            $this->users->save($domainUser);
        }
    }
}
