<?php

declare(strict_types=1);

namespace App\Shared\Database;

/**
 * The core tables whose rows belong to one tenant, and how SQL names the current tenant.
 *
 * The current tenant is a setting of the PostgreSQL session, app.tenant_id. A trigger on each
 * table reads it, so a row inserted without naming its tenant (every ORM insert: no model maps
 * the column) gets the current one, and fails on NOT NULL when there is none. Reads compare the
 * column to the same expression: the ORM through the tenant filter, DBAL by hand.
 */
final class TenantScope
{
    public const string COLUMN = 'tenant_id';

    /**
     * The current tenant's id, or NULL when the session has none. NULLIF: once set and reset,
     * the setting reads as an empty string, which is no UUID.
     */
    public const string CURRENT = "NULLIF(current_setting('app.tenant_id', true), '')::uuid";

    /**
     * Every core table with a tenant_id column, in the core schema.
     *
     * Not listed: core.tenant (the registry), the tables of fedale/setting-bundle (they have a
     * tenant_id of their own), and the users, global identities; their profiles come with the
     * memberships (phase 3 of the plan).
     */
    public const array TABLES = [
        'metadata_object',
        'metadata_field',
        'metadata_view',
        'metadata_form',
        'metadata_translation',
        'metadata_state',
        'schema_change_log',
        'file',
        'template_installation',
        'template_item',
        'template_source',
    ];

    /**
     * Tables of TABLES whose rows may have no tenant: offered to every tenant, read by all of
     * them, written by the landlord only (template_source: the indexes the operator offers).
     */
    public const array SHARED = ['template_source'];

    public static function isScoped(string $table): bool
    {
        return \in_array($table, self::TABLES, true);
    }

    public static function isShared(string $table): bool
    {
        return \in_array($table, self::SHARED, true);
    }
}
