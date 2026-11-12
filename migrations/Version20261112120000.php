<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 1 of docs/internal/multi-tenancy-plan.md: the tenant registry, and a tenant_id on every
 * core table whose rows belong to a tenant. The installation becomes its first tenant, whose
 * records stay in the "data" schema.
 *
 * The list of tables is App\Shared\Database\TenantScope::TABLES, copied: a migration must not
 * change when the code does.
 */
final class Version20261112120000 extends AbstractMigration
{
    private const array TABLES = [
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

    /** The tables whose writes bump the metadata revision (migration Version20261107060749). */
    private const array REVISED_TABLES = ['metadata_object', 'metadata_field', 'metadata_form', 'metadata_view', 'metadata_translation'];

    /**
     * Indexes whose columns gain tenant_id in front: name => [table, columns before, unique].
     * Uniqueness becomes per tenant (two tenants may both have an "account" object).
     */
    private const array INDEXES = [
        'uniq_metadata_object_name' => ['metadata_object', 'name', true],
        'uniq_metadata_object_table_name' => ['metadata_object', 'table_name', true],
        'uniq_metadata_form_name' => ['metadata_form', 'name', true],
        'uniq_metadata_translation_locale_key' => ['metadata_translation', 'locale, message_key', true],
        'uniq_template_installation_name' => ['template_installation', 'name', true],
        'uniq_template_source_name' => ['template_source', 'name', true],
        'uniq_template_item_item_id' => ['template_item', 'template, kind, item_id', true],
        'uniq_template_item_local_id' => ['template_item', 'template, local_id', true],
        'idx_file_sha256' => ['file', 'sha256', false],
        'idx_schema_change_log_object' => ['schema_change_log', 'object_name', false],
    ];

    public function getDescription(): string
    {
        return 'The tenant registry, and a tenant_id on the core tables holding a tenant\'s rows';
    }

    public function up(Schema $schema): void
    {
        // Kept out of the ORM mapping (doctrine.dbal.schema_filter), as metadata_state: read
        // before any tenant is selected, with DBAL.
        $this->addSql('CREATE TABLE core.tenant (
            id UUID DEFAULT gen_random_uuid() NOT NULL,
            slug VARCHAR(63) NOT NULL,
            name VARCHAR(255) NOT NULL,
            data_schema VARCHAR(63) NOT NULL,
            status VARCHAR(20) DEFAULT \'active\' NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id),
            CONSTRAINT tenant_status CHECK (status IN (\'active\', \'suspended\'))
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_tenant_slug ON core.tenant (slug)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tenant_data_schema ON core.tenant (data_schema)');
        $this->addSql('INSERT INTO core.tenant (slug, name, data_schema) VALUES (\'default\', \'Default\', \'data\')');

        // A row inserted without naming its tenant (every ORM insert: no model maps the column)
        // gets the session's, app.tenant_id; with none, it fails on NOT NULL. A trigger rather
        // than a column default: DBAL can't represent an expression as a default, and every
        // migrations:diff would try to drop it.
        $this->addSql('CREATE FUNCTION core.set_tenant_id() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.tenant_id IS NULL THEN
                    NEW.tenant_id := NULLIF(current_setting(\'app.tenant_id\', true), \'\')::uuid;
                END IF;
                RETURN NEW;
            END
        $$');

        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE core.%s ADD tenant_id UUID', $table));
            $this->addSql(\sprintf('UPDATE core.%s SET tenant_id = (SELECT id FROM core.tenant)', $table));
            $this->addSql(\sprintf('ALTER TABLE core.%s ALTER tenant_id SET NOT NULL', $table));
            $this->addSql(\sprintf(
                'ALTER TABLE core.%1$s ADD CONSTRAINT fk_%1$s_tenant FOREIGN KEY (tenant_id) REFERENCES core.tenant (id) ON DELETE CASCADE',
                $table,
            ));
            $this->addSql(\sprintf(
                'CREATE TRIGGER set_tenant_id BEFORE INSERT ON core.%s FOR EACH ROW EXECUTE FUNCTION core.set_tenant_id()',
                $table,
            ));
            // Its own index even where a composite one starts with tenant_id: DBAL takes only an
            // index on exactly the column as one serving the foreign key, and would otherwise
            // add one of its own to the generated schema.
            if ('metadata_state' !== $table) {
                $this->addSql(\sprintf('CREATE INDEX idx_%1$s_tenant ON core.%1$s (tenant_id)', $table));
            }
        }

        foreach (self::INDEXES as $name => [$table, $columns, $unique]) {
            $this->addSql(\sprintf('DROP INDEX core.%s', $name));
            $this->addSql(\sprintf('CREATE %sINDEX %s ON core.%s (tenant_id, %s)', $unique ? 'UNIQUE ' : '', $name, $table, $columns));
        }

        // One row per tenant instead of one for the installation.
        $this->addSql('ALTER TABLE core.metadata_state DROP CONSTRAINT metadata_state_singleton');
        $this->addSql('ALTER TABLE core.metadata_state DROP CONSTRAINT metadata_state_pkey');
        $this->addSql('ALTER TABLE core.metadata_state DROP singleton');
        $this->addSql('ALTER TABLE core.metadata_state ADD PRIMARY KEY (tenant_id)');

        // A write bumps the revision of the tenant whose rows it changed, so one tenant's
        // change leaves the others' caches alone. Per row, the only way to see a row's
        // tenant_id; TRUNCATE has no rows, and bumps every tenant.
        $this->addSql('CREATE OR REPLACE FUNCTION core.bump_metadata_revision() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = \'TRUNCATE\' THEN
                    UPDATE core.metadata_state SET revision = revision + 1;
                ELSIF TG_OP = \'DELETE\' THEN
                    UPDATE core.metadata_state SET revision = revision + 1 WHERE tenant_id = OLD.tenant_id;
                ELSE
                    UPDATE core.metadata_state SET revision = revision + 1 WHERE tenant_id = NEW.tenant_id;
                    IF TG_OP = \'UPDATE\' AND OLD.tenant_id IS DISTINCT FROM NEW.tenant_id THEN
                        UPDATE core.metadata_state SET revision = revision + 1 WHERE tenant_id = OLD.tenant_id;
                    END IF;
                END IF;
                RETURN NULL;
            END
        $$');
        foreach (self::REVISED_TABLES as $table) {
            $this->addSql(\sprintf('DROP TRIGGER bump_metadata_revision ON core.%s', $table));
            $this->addSql(\sprintf(
                'CREATE TRIGGER bump_metadata_revision AFTER INSERT OR UPDATE OR DELETE ON core.%s FOR EACH ROW EXECUTE FUNCTION core.bump_metadata_revision()',
                $table,
            ));
            $this->addSql(\sprintf(
                'CREATE TRIGGER bump_metadata_revision_truncate AFTER TRUNCATE ON core.%s FOR EACH STATEMENT EXECUTE FUNCTION core.bump_metadata_revision()',
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE OR REPLACE FUNCTION core.bump_metadata_revision() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                UPDATE core.metadata_state SET revision = revision + 1;
                RETURN NULL;
            END
        $$');
        foreach (self::REVISED_TABLES as $table) {
            $this->addSql(\sprintf('DROP TRIGGER bump_metadata_revision_truncate ON core.%s', $table));
            $this->addSql(\sprintf('DROP TRIGGER bump_metadata_revision ON core.%s', $table));
            $this->addSql(\sprintf(
                'CREATE TRIGGER bump_metadata_revision AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON core.%s FOR EACH STATEMENT EXECUTE FUNCTION core.bump_metadata_revision()',
                $table,
            ));
        }

        // Back to one tenant: the others' rows can't survive the single-tenant constraints.
        $this->addSql('DELETE FROM core.tenant WHERE id <> (SELECT id FROM core.tenant ORDER BY created_at, id LIMIT 1)');

        $this->addSql('ALTER TABLE core.metadata_state DROP CONSTRAINT metadata_state_pkey');
        $this->addSql('ALTER TABLE core.metadata_state ADD singleton BOOLEAN DEFAULT TRUE NOT NULL');
        $this->addSql('ALTER TABLE core.metadata_state ADD PRIMARY KEY (singleton)');
        $this->addSql('ALTER TABLE core.metadata_state ADD CONSTRAINT metadata_state_singleton CHECK (singleton)');

        foreach (self::INDEXES as $name => [$table, $columns, $unique]) {
            $this->addSql(\sprintf('DROP INDEX core.%s', $name));
            $this->addSql(\sprintf('CREATE %sINDEX %s ON core.%s (%s)', $unique ? 'UNIQUE ' : '', $name, $table, $columns));
        }
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('DROP TRIGGER set_tenant_id ON core.%s', $table));
            // Its indexes and foreign key go with it.
            $this->addSql(\sprintf('ALTER TABLE core.%s DROP tenant_id', $table));
        }
        $this->addSql('DROP FUNCTION core.set_tenant_id()');

        $this->addSql('DROP TABLE core.tenant');
    }
}
