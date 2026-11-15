<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 1 of docs/internal/workflow-phase1-plan.md: the workflows of a workspace, their versions,
 * and their runs.
 *
 * core.workflow and core.workflow_version are the ORM's; the runs (core.workflow_run, its steps,
 * core.workflow_usage) are written with DBAL once a transaction ended, and hidden from the schema
 * tools (doctrine.yaml). Each table has a workspace_id, set by the trigger, and the row-level
 * security policy of every workspace table.
 */
final class Version20261115160000 extends AbstractMigration
{
    private const array TABLES = ['workflow', 'workflow_version', 'workflow_run', 'workflow_run_step', 'workflow_usage'];

    private const string CONDITION = "workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::uuid OR current_setting('app.system', true) = 'on'";

    public function getDescription(): string
    {
        return 'The workflows, their versions and their runs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE core.workflow (name VARCHAR(100) NOT NULL, description TEXT DEFAULT NULL, position INT NOT NULL, created_by VARCHAR(180) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_workflow_name ON core.workflow (workspace_id, name)');
        $this->addSql('CREATE INDEX idx_workflow_workspace ON core.workflow (workspace_id)');
        $this->addSql('ALTER TABLE core.workflow ADD CONSTRAINT fk_workflow_workspace FOREIGN KEY (workspace_id) REFERENCES core.workspace (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE core.workflow_version (number INT NOT NULL, status VARCHAR(20) NOT NULL, source TEXT NOT NULL, definition JSONB DEFAULT NULL, activated_by VARCHAR(180) DEFAULT NULL, activated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, workflow_id UUID NOT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_workflow_version_number ON core.workflow_version (workflow_id, number)');
        $this->addSql('CREATE INDEX IDX_3B8F65472C7C2CBA ON core.workflow_version (workflow_id)');
        $this->addSql('CREATE INDEX idx_workflow_version_workspace ON core.workflow_version (workspace_id)');
        $this->addSql('ALTER TABLE core.workflow_version ADD CONSTRAINT FK_3B8F65472C7C2CBA FOREIGN KEY (workflow_id) REFERENCES core.workflow (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE core.workflow_version ADD CONSTRAINT fk_workflow_version_workspace FOREIGN KEY (workspace_id) REFERENCES core.workspace (id) ON DELETE CASCADE');

        // The runs: kept a while (app.workflow.runs_keep_days), then deleted by workflow:runs:gc.
        $this->addSql(<<<'SQL'
            CREATE TABLE core.workflow_run (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                workflow_id UUID NOT NULL,
                version_id UUID NOT NULL,
                status VARCHAR(20) NOT NULL,
                object VARCHAR(50) NOT NULL,
                record_id UUID DEFAULT NULL,
                action VARCHAR(20) NOT NULL,
                timing VARCHAR(10) NOT NULL,
                triggered_by VARCHAR(180) DEFAULT NULL,
                depth SMALLINT NOT NULL,
                chain JSONB NOT NULL,
                error TEXT DEFAULT NULL,
                started_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
                finished_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_workflow_run_workspace ON core.workflow_run (workspace_id, started_at)');
        $this->addSql('CREATE INDEX idx_workflow_run_workflow ON core.workflow_run (workflow_id, started_at)');
        $this->addSql('ALTER TABLE core.workflow_run ADD CONSTRAINT fk_workflow_run_workspace FOREIGN KEY (workspace_id) REFERENCES core.workspace (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE core.workflow_run ADD CONSTRAINT fk_workflow_run_workflow FOREIGN KEY (workflow_id) REFERENCES core.workflow (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE core.workflow_run ADD CONSTRAINT fk_workflow_run_version FOREIGN KEY (version_id) REFERENCES core.workflow_version (id) ON DELETE CASCADE');

        $this->addSql(<<<'SQL'
            CREATE TABLE core.workflow_run_step (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                run_id UUID NOT NULL,
                position INT NOT NULL,
                key VARCHAR(50) NOT NULL,
                type VARCHAR(30) NOT NULL,
                status VARCHAR(20) NOT NULL,
                input JSONB NOT NULL,
                output JSONB DEFAULT NULL,
                error TEXT DEFAULT NULL,
                duration_ms INT NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_workflow_run_step_run ON core.workflow_run_step (run_id, position)');
        $this->addSql('CREATE INDEX idx_workflow_run_step_workspace ON core.workflow_run_step (workspace_id)');
        $this->addSql('ALTER TABLE core.workflow_run_step ADD CONSTRAINT fk_workflow_run_step_workspace FOREIGN KEY (workspace_id) REFERENCES core.workspace (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE core.workflow_run_step ADD CONSTRAINT fk_workflow_run_step_run FOREIGN KEY (run_id) REFERENCES core.workflow_run (id) ON DELETE CASCADE');

        // The steps run per workspace and day: what quotas per plan will count, later.
        $this->addSql('CREATE TABLE core.workflow_usage (workspace_id UUID NOT NULL, day DATE NOT NULL, steps INT NOT NULL, PRIMARY KEY (workspace_id, day))');
        $this->addSql('ALTER TABLE core.workflow_usage ADD CONSTRAINT fk_workflow_usage_workspace FOREIGN KEY (workspace_id) REFERENCES core.workspace (id) ON DELETE CASCADE');

        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('CREATE TRIGGER set_workspace_id BEFORE INSERT ON core.%s FOR EACH ROW EXECUTE FUNCTION core.set_workspace_id()', $table));
            $this->addSql(\sprintf('ALTER TABLE core.%s ENABLE ROW LEVEL SECURITY', $table));
            $this->addSql(\sprintf('ALTER TABLE core.%s FORCE ROW LEVEL SECURITY', $table));
            $this->addSql(\sprintf('CREATE POLICY workspace_isolation ON core.%1$s USING (%2$s) WITH CHECK (%2$s)', $table, self::CONDITION));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            $this->addSql(\sprintf('DROP TABLE core.%s', $table));
        }
    }
}
