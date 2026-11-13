<?php

declare(strict_types=1);

namespace App\Module\User\Infrastructure\Security;

use App\Module\User\Domain\Model\User;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The user as Symfony Security sees it: a snapshot of the domain User, plus the roles
 * assigned in the RBAC tables, and the tenant it was loaded for.
 *
 * The tenant binds a session to it: this object is what the session keeps from the sign-in,
 * compared on every request with the user loaded again for the request's tenant
 * (isEqualTo()). It binds a remember-me cookie too, as a signature property (security.yaml).
 */
final class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    public const string BASE_ROLE = 'ROLE_USER';

    /**
     * Not promoted, so that it has a default: a user kept by a session of before tenants is
     * unserialized with null here, which no request's tenant equals.
     */
    private ?string $tenantId = null;

    /**
     * @param list<string> $assignedRoles
     */
    public function __construct(
        private readonly string $id,
        private readonly string $email,
        private readonly string $passwordHash,
        private readonly string $displayName,
        private readonly array $assignedRoles = [],
        private readonly ?string $locale = null,
        ?string $tenantId = null,
    ) {
        $this->tenantId = $tenantId;
    }

    /**
     * @param list<string> $assignedRoles
     */
    public static function fromUser(User $user, array $assignedRoles, ?string $tenantId = null): self
    {
        return new self($user->getId()->toRfc4122(), $user->getEmail(), $user->getPasswordHash(), $user->getDisplayName(), $assignedRoles, $user->getLocale(), $tenantId);
    }

    /**
     * The RBAC assignments are keyed by this identifier: the email.
     */
    public function getUserIdentifier(): string
    {
        return '' !== $this->email ? $this->email : throw new \LogicException('A user has an email.');
    }

    public function getRoles(): array
    {
        return array_values(array_unique([self::BASE_ROLE, ...$this->assignedRoles]));
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * The tenant the user was loaded for; empty without one (a command in the landlord's session).
     * A remember-me signature property: a string.
     */
    public function getTenantId(): string
    {
        return $this->tenantId ?? '';
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    /**
     * The language chosen in the profile; null: the browser's. Not compared by isEqualTo():
     * changing it keeps the session.
     */
    public function getLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * Compares the user of the session with the one reloaded on every request. A changed
     * password or changed roles sign the session out: the roles of a token are fixed at
     * sign-in, so a revoked role (ROLE_SUPER_ADMIN) would otherwise keep working until logout.
     * With a remember-me cookie the user is signed back in at once, with the new roles.
     *
     * Another tenant signs it out too: a session opened in one tenant, presented to another
     * (a cookie copied to its host, or shared by a cookie_domain), even by a member of both.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self || $user->id !== $this->id || $user->passwordHash !== $this->passwordHash || $user->tenantId !== $this->tenantId) {
            return false;
        }
        $roles = $this->getRoles();
        $reloaded = $user->getRoles();
        sort($roles);
        sort($reloaded);

        return $roles === $reloaded;
    }
}
