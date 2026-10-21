<?php

declare(strict_types=1);

namespace App\Module\User\Domain\Model;

use App\Module\User\Domain\Exception\InvalidUser;
use Symfony\Component\Uid\Uuid;

/**
 * Who can sign in: the login identity only. Personal data lives in UserProfile.
 */
class User
{
    private string $email;
    private bool $isActive = true;
    private ?UserProfile $profile = null;
    private \DateTimeImmutable $createdAt;
    private ?\DateTimeImmutable $lastLoginAt = null;

    /**
     * @param string $passwordHash already hashed: the domain never sees a plain password
     */
    public function __construct(
        private Uuid $id,
        string $email,
        private string $passwordHash,
    ) {
        $this->email = self::normalizeEmail($email);
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Emails are compared case-insensitively: they are stored lowercase.
     */
    public static function normalizeEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw InvalidUser::invalidEmail($email);
        }

        return $email;
    }

    public function attachProfile(UserProfile $profile): void
    {
        $this->profile = $profile;
    }

    public function changePasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function recordLogin(\DateTimeImmutable $at): void
    {
        $this->lastLoginAt = $at;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getProfile(): ?UserProfile
    {
        return $this->profile;
    }

    /**
     * The name shown in the UI: the profile's full name, or the email.
     */
    public function getDisplayName(): string
    {
        $name = $this->profile?->getFullName() ?? '';

        return '' !== $name ? $name : $this->email;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }
}
