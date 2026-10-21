<?php

declare(strict_types=1);

namespace App\Shared\Database;

/**
 * PostgreSQL schema holding the record tables generated from metadata.
 * Static core tables live in the "core" schema and are managed by Doctrine migrations.
 */
final class DataSchema
{
    public const string NAME = 'data';
}
