<?php

declare(strict_types=1);

namespace App\Module\User\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * The person behind a user, one-to-one with User: kept apart from the login identity.
 */
class UserProfile
{
    private ?string $firstName;
    private ?string $lastName;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        private Uuid $id,
        private User $user,
        ?string $firstName = null,
        ?string $lastName = null,
    ) {
        $this->rename($firstName, $lastName);
        $user->attachProfile($this);
    }

    public function rename(?string $firstName, ?string $lastName): void
    {
        $this->firstName = self::clean($firstName);
        $this->lastName = self::clean($lastName);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getFullName(): string
    {
        return trim(($this->firstName ?? '').' '.($this->lastName ?? ''));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function clean(?string $value): ?string
    {
        $value = null !== $value ? trim($value) : null;

        return '' === $value ? null : $value;
    }
}
