<?php

declare(strict_types=1);

namespace App\Module\User\Infrastructure\Security;

use App\Module\User\Domain\Repository\MembershipRepository;
use App\Module\User\Domain\Repository\UserRepository;
use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\SystemConsole;
use Fedale\RbacBundle\Security\AssignmentRolesProvider;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads active users by email, with the roles assigned to them in the RBAC tables (read on
 * every request: when they changed, SecurityUser::isEqualTo() signs the session out).
 *
 * Users are global, so only the members of the request's workspace are found: signing in to
 * another workspace fails as a wrong password does, and since the session's user is loaded again
 * on every request (refreshUser()), a session replayed on another workspace's host, or one whose
 * membership was removed, is signed out. The roles are the workspace's: its RBAC schema is first
 * after core in the session's search_path.
 *
 * On the system console only its system admins are found, with ROLE_SYSTEM_ADMIN alone:
 * revoking the flag signs their console session out at its next request.
 *
 * @implements UserProviderInterface<SecurityUser>
 */
final class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly CurrentWorkspace $workspace,
        private readonly AssignmentRolesProvider $roles,
        private readonly RequestStack $requests,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): SecurityUser
    {
        $user = $this->users->findByEmail($identifier);
        // The system console (its own host, the system session): its system admins
        // only, and no workspace's roles, which have no RBAC tables there. Read per request: this
        // service outlives one in a worker.
        if (true === $this->requests->getMainRequest()?->attributes->get(SystemConsole::REQUEST_ATTRIBUTE)) {
            if (null === $user || !$user->isActive() || !$user->isSystemAdmin()) {
                $exception = new UserNotFoundException();
                $exception->setUserIdentifier($identifier);

                throw $exception;
            }

            return SecurityUser::fromUser($user, [SystemConsole::ROLE]);
        }

        // No workspace (a command in the system session): any active user, as before workspaces.
        if (null === $user || !$user->isActive()
            || ($this->workspace->isSelected() && !$this->memberships->isMember($this->workspace->id(), $user->getId()))) {
            // Same error for unknown, deactivated and other workspaces' users: no account enumeration.
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return SecurityUser::fromUser(
            $user,
            array_values($this->roles->getRolesFor($user->getEmail())),
            $this->workspace->isSelected() ? $this->workspace->id() : null,
        );
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
