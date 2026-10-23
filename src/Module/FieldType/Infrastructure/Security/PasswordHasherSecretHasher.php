<?php

declare(strict_types=1);

namespace App\Module\FieldType\Infrastructure\Security;

use App\Module\FieldType\Contract\SecretHasherInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Hashes with the "record_secret" hasher of security.yaml ("auto": bcrypt or argon2).
 */
final class PasswordHasherSecretHasher implements SecretHasherInterface
{
    private const string HASHER = 'record_secret';

    public function __construct(
        private readonly PasswordHasherFactoryInterface $hashers,
    ) {
    }

    public function hash(string $plain): string
    {
        return $this->hasher()->hash($plain);
    }

    public function verify(string $hash, string $plain): bool
    {
        return $this->hasher()->verify($hash, $plain);
    }

    private function hasher(): PasswordHasherInterface
    {
        return $this->hashers->getPasswordHasher(self::HASHER);
    }
}
