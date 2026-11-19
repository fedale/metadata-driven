<?php

declare(strict_types=1);

namespace App\Shared\Database;

/**
 * The core tables whose rows belong to one workspace, and how SQL names the current workspace.
 *
 * The current workspace is a setting of the PostgreSQL session, app.workspace_id. A trigger on each
 * table reads it, so a row inserted without naming its workspace (every ORM insert: no model maps
 * the column) gets the current one, and fails on NOT NULL when there is none. Reads compare the
 * column to the same expression: the ORM through the workspace filter, DBAL by hand.
 */
final class WorkspaceScope
{
    public const string COLUMN = 'workspace_id';

    /**
     * The current workspace's id, or NULL when the session has none. NULLIF: once set and reset,
     * the setting reads as an empty string, which is no UUID.
     */
    public const string CURRENT = "NULLIF(current_setting('app.workspace_id', true), '')::uuid";

    /**
     * Every core table with a workspace_id column, in the core schema.
     *
     * Not listed: core.workspace (the registry), the tables of fedale/setting-bundle (they have a
     * workspace_id of their own), and the users, global identities; their profiles come with the
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
        'package_installation',
        'package_item',
        'package_source',
        'workflow',
        'workflow_version',
        'workflow_run',
        'workflow_run_step',
        'workflow_usage',
        'workflow_secret',
        'workflow_webhook',
        'notification',
    ];

    /**
     * Tables of TABLES whose rows may have no workspace: offered to every workspace, read by all of
     * them, written by the system only (package_source: the indexes the system admin offers).
     */
    public const array SHARED = ['package_source'];

    /**
     * Tables of TABLES whose rows with no workspace are the system's: the system admin's workflows
     * and their runs, seen by the system session only.
     */
    public const array SYSTEM_OWNED = ['workflow', 'workflow_version', 'workflow_run', 'workflow_run_step'];

    /**
     * The current workspace's rows, or with none (the system session) the system's: the condition
     * on a SYSTEM_OWNED table, for the column given.
     */
    public static function currentOrSystem(string $column): string
    {
        return \sprintf('%s IS NOT DISTINCT FROM %s', $column, self::CURRENT);
    }

    public static function isScoped(string $table): bool
    {
        return \in_array($table, self::TABLES, true);
    }

    public static function isShared(string $table): bool
    {
        return \in_array($table, self::SHARED, true);
    }

    public static function isSystemOwned(string $table): bool
    {
        return \in_array($table, self::SYSTEM_OWNED, true);
    }
}
