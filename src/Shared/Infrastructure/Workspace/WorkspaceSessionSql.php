<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace;

use App\Shared\Database\CoreSchema;
use App\Shared\Workspace\Workspace;

/**
 * The SQL that points a database session at a workspace, or at none.
 *
 * Three settings of the session: app.workspace_id, which the insert trigger, the ORM filter, the
 * DBAL queries and the row-level security policies read; app.system, 'on' in the system
 * session only, which the policies let see every workspace's rows; and the search_path, core first
 * (doctrine_migration_versions and every unqualified core table must resolve there), then the
 * workspace's RBAC schema, where fedale/rbac-bundle finds its tables unqualified.
 *
 * set_config(…, false) rather than SET: it takes its value as an expression, and lasts for the
 * session, not only the transaction.
 */
final class WorkspaceSessionSql
{
    /**
     * Empty RBAC tables (migration Version20261113130000, renamed by Version20261114100000) for
     * the sessions of no workspace, in their search_path: fedale/rbac-bundle finds tables there, and
     * they grant nothing.
     */
    public const string NO_WORKSPACE_RBAC = 'no_workspace_rbac';

    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string SLUG = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    public static function select(Workspace $workspace): string
    {
        if (1 !== preg_match(self::UUID, $workspace->id)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is no workspace id.', $workspace->id));
        }

        // The schema names are checked by WorkspaceRegistry: lower case letters, digits, underscores.
        return \sprintf(
            "SELECT set_config('app.workspace_id', '%s', false), set_config('app.system', '', false), set_config('search_path', '%s, %s, public', false)",
            $workspace->id,
            CoreSchema::NAME,
            $workspace->rbacSchema,
        );
    }

    /**
     * No workspace, and not the system either: the row-level security policies let such a session see
     * and write no workspace's rows. A connection's state when nobody selected anything.
     */
    public static function none(): string
    {
        return \sprintf("SELECT set_config('app.workspace_id', '', false), set_config('app.system', '', false), set_config('search_path', '%s, %s, public', false)", CoreSchema::NAME, self::NO_WORKSPACE_RBAC);
    }

    /**
     * The system session: no workspace, and the policies let it see and write every workspace's
     * rows (the migrations, the workspace commands). An explicit mark rather than the absence of a
     * workspace, so that a session that lost its workspace sees nothing instead of everything.
     */
    public static function system(): string
    {
        return \sprintf("SELECT set_config('app.workspace_id', '', false), set_config('app.system', 'on', false), set_config('search_path', '%s, %s, public', false)", CoreSchema::NAME, self::NO_WORKSPACE_RBAC);
    }

    /**
     * The workspace of a slug, looked up by the database itself: for a connection being opened,
     * before any service may query it. None when the slug names no workspace, or when core.workspace
     * is not as this code expects yet (a database before its migrations: PL/pgSQL plans a query
     * only when it runs it, hence no %ROWTYPE).
     */
    public static function bySlug(string $slug): string
    {
        if (1 !== preg_match(self::SLUG, $slug)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is no workspace slug: lower case letters, digits and dashes.', $slug));
        }

        return \sprintf(<<<'SQL'
            DO $$
            DECLARE
                found_id uuid;
                found_rbac_schema text;
            BEGIN
                PERFORM set_config('app.workspace_id', '', false);
                PERFORM set_config('app.system', '', false);
                PERFORM set_config('search_path', '%1$s, %3$s, public', false);
                -- A block of its own: its error handler rolls back what the block did, and only that.
                BEGIN
                    SELECT t.id, t.rbac_schema INTO found_id, found_rbac_schema FROM %1$s.workspace t WHERE t.slug = '%2$s';
                    IF FOUND THEN
                        PERFORM set_config('app.workspace_id', found_id::text, false);
                        PERFORM set_config('search_path', '%1$s, ' || quote_ident(found_rbac_schema) || ', public', false);
                    END IF;
                EXCEPTION
                    WHEN undefined_table OR undefined_column THEN
                        NULL;
                END;
            END
            $$
            SQL, CoreSchema::NAME, $slug, self::NO_WORKSPACE_RBAC);
    }
}
