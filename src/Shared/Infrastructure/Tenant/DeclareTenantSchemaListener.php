<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant;

use App\Shared\Database\CoreSchema;
use App\Shared\Database\TenantScope;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Brings the schema generated from the ORM mapping in line with what the tenants add to it, so
 * that migrations:diff stays empty. Only schema tools run this listener.
 *
 * - The tenant_id column, its foreign key and its indexes, as migration Version20261112120000
 *   created them. No model maps the column (the domain doesn't know about tenants; a trigger
 *   fills it in, the tenant filter reads it), so the diff would drop it.
 * - No fedale/rbac-bundle tables: the bundle maps them, unqualified, but each tenant has its own,
 *   in its RBAC schema, hidden from the schema tools (doctrine.yaml); the diff would create them
 *   in core.
 * - Every tenant's schemas, declared: their tables are hidden, but schema names are not
 *   filtered, and every diff would emit a spurious "CREATE SCHEMA" in down().
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class DeclareTenantSchemaListener
{
    /** Indexes that start with tenant_id, in front of the columns the mapping gives them. */
    private const array TENANT_LEADING_INDEXES = [
        'uniq_metadata_object_name',
        'uniq_metadata_object_table_name',
        'uniq_metadata_form_name',
        'uniq_metadata_translation_locale_key',
        'uniq_template_installation_name',
        'uniq_template_source_name',
        'uniq_template_item_item_id',
        'uniq_template_item_local_id',
        'idx_file_sha256',
        'idx_schema_change_log_object',
    ];

    /** fedale/rbac-bundle's tables, by their unqualified names. */
    private const array RBAC_TABLES = ['auth_assignment', 'auth_item', 'auth_item_child', 'auth_rule'];

    public function __construct(private readonly TenantRegistry $tenants)
    {
    }

    /**
     * Deprecated methods of Schema and Table: since DBAL 4.5 a schema is rebuilt through an
     * editor, but the event hands over a schema to change in place, with no way to replace it.
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();
        foreach (self::RBAC_TABLES as $name) {
            if ($schema->hasTable($name)) {
                $schema->dropTable($name);
            }
        }
        $namespaces = [TenantSessionSql::NO_TENANT_RBAC];
        foreach ($this->tenants->all() as $tenant) {
            $namespaces = [...$namespaces, $tenant->dataSchema, $tenant->rbacSchema];
        }
        foreach ($namespaces as $namespace) {
            if (!$schema->hasNamespace($namespace)) {
                $schema->createNamespace($namespace);
            }
        }
        foreach (TenantScope::TABLES as $name) {
            $qualified = CoreSchema::NAME.'.'.$name;
            // metadata_state is not mapped: hidden from the schema tools (doctrine.yaml).
            if ($schema->hasTable($qualified)) {
                $this->declare($schema->getTable($qualified), $name);
            }
        }
    }

    private function declare(Table $table, string $name): void
    {
        $table->addColumn(TenantScope::COLUMN, 'guid', ['notnull' => !TenantScope::isShared($name)]);
        if (TenantScope::isShared($name)) {
            // Migration Version20261112190000: the names of the rows offered to every tenant. The
            // predicate as PostgreSQL reports it, parentheses included, or the diff sees a change.
            $table->addUniqueIndex(['name'], \sprintf('uniq_%s_shared_name', $name), ['where' => '('.TenantScope::COLUMN.' IS NULL)']);
        }

        foreach ($table->getIndexes() as $index) {
            $indexName = $index->getName();
            if (!\in_array($indexName, self::TENANT_LEADING_INDEXES, true)) {
                continue;
            }
            $columns = [TenantScope::COLUMN, ...$index->getColumns()];
            $unique = $index->isUnique();
            $table->dropIndex($indexName);
            $unique ? $table->addUniqueIndex($columns, $indexName) : $table->addIndex($columns, $indexName);
        }
        // Before the foreign key: DBAL takes only an index on exactly its column as serving it,
        // and would otherwise add one of its own, under a name the database doesn't have.
        $table->addIndex([TenantScope::COLUMN], \sprintf('idx_%s_tenant', $name));
        $table->addForeignKeyConstraint(
            CoreSchema::NAME.'.tenant',
            [TenantScope::COLUMN],
            ['id'],
            ['onDelete' => 'CASCADE'],
            \sprintf('fk_%s_tenant', $name),
        );
    }
}
