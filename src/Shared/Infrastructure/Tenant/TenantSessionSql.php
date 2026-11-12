<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant;

use App\Shared\Database\CoreSchema;
use App\Shared\Tenant\Tenant;

/**
 * The SQL that points a database session at a tenant, or at none.
 *
 * Two settings of the session: app.tenant_id, which the insert trigger, the ORM filter, the
 * DBAL queries and the row-level security policies read; and the search_path, core first
 * (doctrine_migration_versions and every unqualified core table must resolve there), then the
 * tenant's RBAC schema, where fedale/rbac-bundle finds its tables unqualified.
 *
 * set_config(…, false) rather than SET: it takes its value as an expression, and lasts for the
 * session, not only the transaction.
 */
final class TenantSessionSql
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string SLUG = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    public static function select(Tenant $tenant): string
    {
        if (1 !== preg_match(self::UUID, $tenant->id)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is no tenant id.', $tenant->id));
        }

        // The schema names are checked by TenantRegistry: lower case letters, digits, underscores.
        return \sprintf(
            "SELECT set_config('app.tenant_id', '%s', false), set_config('search_path', '%s, %s, public', false)",
            $tenant->id,
            CoreSchema::NAME,
            $tenant->rbacSchema,
        );
    }

    public static function none(): string
    {
        return \sprintf("SELECT set_config('app.tenant_id', '', false), set_config('search_path', '%s, public', false)", CoreSchema::NAME);
    }

    /**
     * The tenant of a slug, looked up by the database itself: for a connection being opened,
     * before any service may query it. None when the slug names no tenant, or when core.tenant
     * is not as this code expects yet (a database before its migrations: PL/pgSQL plans a query
     * only when it runs it, hence no %ROWTYPE).
     */
    public static function bySlug(string $slug): string
    {
        if (1 !== preg_match(self::SLUG, $slug)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is no tenant slug: lower case letters, digits and dashes.', $slug));
        }

        return \sprintf(<<<'SQL'
            DO $$
            DECLARE
                found_id uuid;
                found_rbac_schema text;
            BEGIN
                PERFORM set_config('app.tenant_id', '', false);
                PERFORM set_config('search_path', '%1$s, public', false);
                -- A block of its own: its error handler rolls back what the block did, and only that.
                BEGIN
                    SELECT t.id, t.rbac_schema INTO found_id, found_rbac_schema FROM %1$s.tenant t WHERE t.slug = '%2$s';
                    IF FOUND THEN
                        PERFORM set_config('app.tenant_id', found_id::text, false);
                        PERFORM set_config('search_path', '%1$s, ' || quote_ident(found_rbac_schema) || ', public', false);
                    END IF;
                EXCEPTION
                    WHEN undefined_table OR undefined_column THEN
                        NULL;
                END;
            END
            $$
            SQL, CoreSchema::NAME, $slug);
    }
}
