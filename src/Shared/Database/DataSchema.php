<?php

declare(strict_types=1);

namespace App\Shared\Database;

/**
 * PostgreSQL schema holding the record tables generated from metadata.
 * Static tables live in the "core" schema ({@see CoreSchema}) and are managed by Doctrine migrations.
 */
final class DataSchema
{
    public const string NAME = 'data';
}
