<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace;

use App\Shared\Database\CoreSchema;
use App\Shared\Database\WorkspaceScope;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Brings the schema generated from the ORM mapping in line with what the workspaces add to it, so
 * that migrations:diff stays empty. Only schema tools run this listener.
 *
 * - The workspace_id column, its foreign key and its indexes, as migration Version20261112120000
 *   created them. No model maps the column (the domain doesn't know about workspaces; a trigger
 *   fills it in, the workspace filter reads it), so the diff would drop it.
 * - No fedale/rbac-bundle tables: the bundle maps them, unqualified, but each workspace has its own,
 *   in its RBAC schema, hidden from the schema tools (doctrine.yaml); the diff would create them
 *   in core.
 * - Every workspace's schemas, declared: their tables are hidden, but schema names are not
 *   filtered, and every diff would emit a spurious "CREATE SCHEMA" in down().
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class DeclareWorkspaceSchemaListener
{
    /** Indexes that start with workspace_id, in front of the columns the mapping gives them. */
    private const array WORKSPACE_LEADING_INDEXES = [
        'uniq_metadata_object_name',
        'uniq_metadata_object_table_name',
        'uniq_metadata_form_name',
        'uniq_metadata_translation_locale_key',
        'uniq_package_installation_name',
        'uniq_package_source_name',
        'uniq_package_item_item_id',
        'uniq_package_item_local_id',
        'idx_file_sha256',
        'idx_schema_change_log_object',
        'uniq_workflow_name',
    ];

    /** fedale/rbac-bundle's tables, by their unqualified names. */
    private const array RBAC_TABLES = ['auth_assignment', 'auth_item', 'auth_item_child', 'auth_rule'];

    public function __construct(private readonly WorkspaceRegistry $workspaces)
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
        $namespaces = [WorkspaceSessionSql::NO_WORKSPACE_RBAC];
        foreach ($this->workspaces->all() as $workspace) {
            $namespaces = [...$namespaces, $workspace->dataSchema, $workspace->rbacSchema];
        }
        foreach ($namespaces as $namespace) {
            if (!$schema->hasNamespace($namespace)) {
                $schema->createNamespace($namespace);
            }
        }
        foreach (WorkspaceScope::TABLES as $name) {
            $qualified = CoreSchema::NAME.'.'.$name;
            // metadata_state is not mapped: hidden from the schema tools (doctrine.yaml).
            if ($schema->hasTable($qualified)) {
                $this->declare($schema->getTable($qualified), $name);
            }
        }
    }

    private function declare(Table $table, string $name): void
    {
        $table->addColumn(WorkspaceScope::COLUMN, 'guid', ['notnull' => !WorkspaceScope::isShared($name)]);
        if (WorkspaceScope::isShared($name)) {
            // Migration Version20261112190000: the names of the rows offered to every workspace. The
            // predicate as PostgreSQL reports it, parentheses included, or the diff sees a change.
            $table->addUniqueIndex(['name'], \sprintf('uniq_%s_shared_name', $name), ['where' => '('.WorkspaceScope::COLUMN.' IS NULL)']);
        }

        foreach ($table->getIndexes() as $index) {
            $indexName = $index->getName();
            if (!\in_array($indexName, self::WORKSPACE_LEADING_INDEXES, true)) {
                continue;
            }
            $columns = [WorkspaceScope::COLUMN, ...$index->getColumns()];
            $unique = $index->isUnique();
            $table->dropIndex($indexName);
            $unique ? $table->addUniqueIndex($columns, $indexName) : $table->addIndex($columns, $indexName);
        }
        // Before the foreign key: DBAL takes only an index on exactly its column as serving it,
        // and would otherwise add one of its own, under a name the database doesn't have.
        $table->addIndex([WorkspaceScope::COLUMN], \sprintf('idx_%s_workspace', $name));
        $table->addForeignKeyConstraint(
            CoreSchema::NAME.'.workspace',
            [WorkspaceScope::COLUMN],
            ['id'],
            ['onDelete' => 'CASCADE'],
            \sprintf('fk_%s_workspace', $name),
        );
    }
}
