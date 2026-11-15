<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 3 of docs/internal/vocabulary-rename-plan.md: a tenant is now a workspace.
 *
 * Every name in core that says "tenant" follows: the tables core.tenant, core.tenant_membership
 * and core.tenant_invitation, the sequence of the workspace numbers, the tenant_id columns, the
 * indexes, constraints, policies and triggers named after them, the trigger functions (whose
 * bodies name the column and the session setting), the session setting app.tenant_id the
 * policies read, and the RBAC schema of the sessions with no workspace, no_tenant_rbac.
 *
 * Not renamed: the tables of the bundles, which keep their own word, core.setting and
 * core.setting_scoped (fedale/setting-bundle) and auth_assignment (fedale/rbac-bundle); and the
 * names of the schemas made for each workspace (t_<id>_data, t_<id>_rbac), stored in
 * core.workspace and never derived again.
 */
final class Version20261115110000 extends AbstractMigration
{
    /** The tables whose tenant_id column is renamed: the application's, not the bundles'. */
    private const array SCOPED_TABLES = [
        'file', 'metadata_field', 'metadata_form', 'metadata_object', 'metadata_state', 'metadata_translation',
        'metadata_view', 'schema_change_log', 'template_installation', 'template_item', 'template_source',
    ];

    /** The bundles' tables in core: nothing of theirs is renamed. */
    private const array BUNDLE_TABLES = ['setting', 'setting_scoped'];

    public function getDescription(): string
    {
        return 'Rename the tenants to workspaces';
    }

    public function up(Schema $schema): void
    {
        $this->rename('tenant', 'workspace');
    }

    public function down(Schema $schema): void
    {
        $this->rename('workspace', 'tenant');
    }

    private function rename(string $from, string $to): void
    {
        $this->addSql(\sprintf('ALTER SCHEMA no_%s_rbac RENAME TO no_%s_rbac', $from, $to));

        foreach (['', '_membership', '_invitation'] as $suffix) {
            $this->addSql(\sprintf('ALTER TABLE core.%1$s%2$s RENAME TO %3$s%2$s', $from, $suffix, $to));
        }
        $this->addSql(\sprintf('ALTER SEQUENCE core.%s_number_seq RENAME TO %s_number_seq', $from, $to));

        foreach ([...self::SCOPED_TABLES, $to.'_membership', $to.'_invitation'] as $table) {
            $this->addSql(\sprintf('ALTER TABLE core.%s RENAME COLUMN %s_id TO %s_id', $table, $from, $to));
        }

        // The functions name the column and the session setting in their bodies: made again.
        $this->addSql(\sprintf(<<<'SQL'
            CREATE FUNCTION core.set_%2$s_id() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.%2$s_id IS NULL THEN
                    NEW.%2$s_id := NULLIF(current_setting('app.%2$s_id', true), '')::uuid;
                END IF;
                RETURN NEW;
            END
            $$
            SQL, $from, $to));
        foreach (self::SCOPED_TABLES as $table) {
            $this->addSql(\sprintf('DROP TRIGGER set_%s_id ON core.%s', $from, $table));
            $this->addSql(\sprintf('CREATE TRIGGER set_%1$s_id BEFORE INSERT ON core.%2$s FOR EACH ROW EXECUTE FUNCTION core.set_%1$s_id()', $to, $table));
        }
        $this->addSql(\sprintf('DROP FUNCTION core.set_%s_id()', $from));
        $this->addSql(\sprintf(<<<'SQL'
            CREATE OR REPLACE FUNCTION core.bump_metadata_revision() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'TRUNCATE' THEN
                    UPDATE core.metadata_state SET revision = revision + 1;
                ELSIF TG_OP = 'DELETE' THEN
                    UPDATE core.metadata_state SET revision = revision + 1 WHERE %1$s_id = OLD.%1$s_id;
                ELSE
                    UPDATE core.metadata_state SET revision = revision + 1 WHERE %1$s_id = NEW.%1$s_id;
                    IF TG_OP = 'UPDATE' AND OLD.%1$s_id IS DISTINCT FROM NEW.%1$s_id THEN
                        UPDATE core.metadata_state SET revision = revision + 1 WHERE %1$s_id = OLD.%1$s_id;
                    END IF;
                END IF;
                RETURN NULL;
            END
            $$
            SQL, $to));

        // Constraints first (a primary key or a unique constraint takes its index along), then
        // the indexes left, then the policies: their names, and the setting their expressions
        // read. Column references in expressions follow a renamed column by themselves.
        $this->addSql(\sprintf(<<<'SQL'
            DO $$
            DECLARE
                r record;
            BEGIN
                FOR r IN
                    SELECT c.conname, t.relname
                    FROM pg_constraint c
                    JOIN pg_class t ON t.oid = c.conrelid
                    JOIN pg_namespace n ON n.oid = t.relnamespace
                    WHERE n.nspname = 'core' AND c.conname LIKE '%%%1$s%%' AND t.relname <> ALL (%3$s)
                LOOP
                    EXECUTE format('ALTER TABLE core.%%I RENAME CONSTRAINT %%I TO %%I', r.relname, r.conname, replace(r.conname, '%1$s', '%2$s'));
                END LOOP;
                FOR r IN
                    SELECT indexname, tablename FROM pg_indexes
                    WHERE schemaname = 'core' AND indexname LIKE '%%%1$s%%' AND tablename <> ALL (%3$s)
                LOOP
                    EXECUTE format('ALTER INDEX core.%%I RENAME TO %%I', r.indexname, replace(r.indexname, '%1$s', '%2$s'));
                END LOOP;
                FOR r IN
                    SELECT schemaname, tablename, policyname, qual, with_check FROM pg_policies
                    WHERE schemaname = 'core'
                LOOP
                    IF r.qual IS NOT NULL THEN
                        EXECUTE format('ALTER POLICY %%I ON %%I.%%I USING (%%s)', r.policyname, r.schemaname, r.tablename, replace(r.qual, '''app.%1$s_id''', '''app.%2$s_id'''));
                    END IF;
                    IF r.with_check IS NOT NULL THEN
                        EXECUTE format('ALTER POLICY %%I ON %%I.%%I WITH CHECK (%%s)', r.policyname, r.schemaname, r.tablename, replace(r.with_check, '''app.%1$s_id''', '''app.%2$s_id'''));
                    END IF;
                    IF r.policyname LIKE '%1$s%%' THEN
                        EXECUTE format('ALTER POLICY %%I ON %%I.%%I RENAME TO %%I', r.policyname, r.schemaname, r.tablename, replace(r.policyname, '%1$s', '%2$s'));
                    END IF;
                END LOOP;
            END
            $$
            SQL, $from, $to, "ARRAY['".implode("', '", self::BUNDLE_TABLES)."']::name[]"));
    }
}
