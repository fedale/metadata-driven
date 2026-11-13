<?php

declare(strict_types=1);

namespace App\Module\User\Infrastructure\Security;

use App\Module\User\Domain\Repository\MembershipRepository;
use App\Module\User\Domain\Repository\UserRepository;
use App\Shared\Tenant\CurrentTenant;
use App\Shared\Tenant\OperatorConsole;
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
 * Users are global, so only the members of the request's tenant are found: signing in to
 * another tenant fails as a wrong password does, and since the session's user is loaded again
 * on every request (refreshUser()), a session replayed on another tenant's host, or one whose
 * membership was removed, is signed out. The roles are the tenant's: its RBAC schema is first
 * after core in the session's search_path.
 *
 * On the console of the installation only its operators are found, with ROLE_OPERATOR alone:
 * revoking the flag signs their console session out at its next request.
 *
 * @implements UserProviderInterface<SecurityUser>
 */
final class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly CurrentTenant $tenant,
        private readonly AssignmentRolesProvider $roles,
        private readonly RequestStack $requests,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): SecurityUser
    {
        $user = $this->users->findByEmail($identifier);
        // The console of the installation (its own host, the landlord's session): its operators
        // only, and no tenant's roles, which have no RBAC tables there. Read per request: this
        // service outlives one in a worker.
        if (true === $this->requests->getMainRequest()?->attributes->get(OperatorConsole::REQUEST_ATTRIBUTE)) {
            if (null === $user || !$user->isActive() || !$user->isOperator()) {
                $exception = new UserNotFoundException();
                $exception->setUserIdentifier($identifier);

                throw $exception;
            }

            return SecurityUser::fromUser($user, [OperatorConsole::ROLE]);
        }

        // No tenant (a command in the landlord's session): any active user, as before tenants.
        if (null === $user || !$user->isActive()
            || ($this->tenant->isSelected() && !$this->memberships->isMember($this->tenant->id(), $user->getId()))) {
            // Same error for unknown, deactivated and other tenants' users: no account enumeration.
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return SecurityUser::fromUser(
            $user,
            array_values($this->roles->getRolesFor($user->getEmail())),
            $this->tenant->isSelected() ? $this->tenant->id() : null,
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
