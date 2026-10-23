<?php

declare(strict_types=1);

namespace App\Module\FieldType\Contract;

/**
 * One-way hashing of the secrets a field stores (passwords), kept free of the framework.
 */
interface SecretHasherInterface
{
    public function hash(string $plain): string;

    public function verify(string $hash, string $plain): bool;
}
