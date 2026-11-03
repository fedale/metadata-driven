<?php

declare(strict_types=1);

namespace App\Shared\Database;

/**
 * PostgreSQL schema holding every static table: definitions, users, settings, RBAC and the
 * migration versions. It is first in the search_path, so the tables that name no schema (the
 * bundles', doctrine_migration_versions) land here too.
 */
final class CoreSchema
{
    public const string NAME = 'core';
}
