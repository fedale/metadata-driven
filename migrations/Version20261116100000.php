<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The system admin's workflows: rows with no workspace in the workflow tables, seen by the system
 * session only (the row-level security already lets no workspace see them). Their names are
 * unique among the system's.
 */
final class Version20261116100000 extends AbstractMigration
{
    private const array TABLES = ['workflow', 'workflow_version', 'workflow_run', 'workflow_run_step'];

    public function getDescription(): string
    {
        return 'The system admin\'s workflows: no workspace';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE core.%s ALTER workspace_id DROP NOT NULL', $table));
        }
        $this->addSql('CREATE UNIQUE INDEX uniq_workflow_system_name ON core.workflow (name) WHERE (workspace_id IS NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX core.uniq_workflow_system_name');
        foreach (array_reverse(self::TABLES) as $table) {
            $this->addSql(\sprintf('DELETE FROM core.%s WHERE workspace_id IS NULL', $table));
            $this->addSql(\sprintf('ALTER TABLE core.%s ALTER workspace_id SET NOT NULL', $table));
        }
    }
}
