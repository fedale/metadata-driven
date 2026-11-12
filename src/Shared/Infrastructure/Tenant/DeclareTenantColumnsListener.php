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
 * Adds the tenant_id column, its foreign key and its indexes to the schema generated from the
 * ORM mapping, as migration Version20261112120000 created them.
 *
 * No model maps the column (the domain doesn't know about tenants; a trigger fills it in, the
 * tenant filter reads it), so without this every migrations:diff would drop it. Only schema
 * tools run this listener.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class DeclareTenantColumnsListener
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

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();
        foreach (TenantScope::TABLES as $name) {
            $qualified = CoreSchema::NAME.'.'.$name;
            // metadata_state is not mapped: hidden from the schema tools (doctrine.yaml).
            if ($schema->hasTable($qualified)) {
                $this->declare($schema->getTable($qualified), $name);
            }
        }
    }

    /**
     * The deprecated mutators of Table: since DBAL 4.5 a table is rebuilt through an editor,
     * but the event hands over a schema to change in place, with no way to replace a table.
     */
    private function declare(Table $table, string $name): void
    {
        $table->addColumn(TenantScope::COLUMN, 'guid', ['notnull' => true]);

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
