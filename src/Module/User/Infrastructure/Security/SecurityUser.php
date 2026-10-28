<?php

declare(strict_types=1);

namespace App\Module\User\Infrastructure\Security;

use App\Module\User\Domain\Model\User;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The user as Symfony Security sees it: a snapshot of the domain User, plus the roles
 * assigned in the RBAC tables.
 */
final class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    public const string BASE_ROLE = 'ROLE_USER';

    /**
     * @param list<string> $assignedRoles
     */
    public function __construct(
        private readonly string $id,
        private readonly string $email,
        private readonly string $passwordHash,
        private readonly string $displayName,
        private readonly array $assignedRoles = [],
    ) {
    }

    /**
     * @param list<string> $assignedRoles
     */
    public static function fromUser(User $user, array $assignedRoles): self
    {
        return new self($user->getId()->toRfc4122(), $user->getEmail(), $user->getPasswordHash(), $user->getDisplayName(), $assignedRoles);
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

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    /**
     * Compares the user of the session with the one reloaded on every request. A changed
     * password or changed roles sign the session out: the roles of a token are fixed at
     * sign-in, so a revoked role (ROLE_SUPER_ADMIN) would otherwise keep working until logout.
     * With a remember-me cookie the user is signed back in at once, with the new roles.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self || $user->id !== $this->id || $user->passwordHash !== $this->passwordHash) {
            return false;
        }
        $roles = $this->getRoles();
        $reloaded = $user->getRoles();
        sort($roles);
        sort($reloaded);

        return $roles === $reloaded;
    }
}
