<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * docs/internal/csv-import-background-plan.md: the imports checked and run by the worker.
 */
final class Version20261120100000 extends AbstractMigration
{
    private const string CONDITION = "workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::uuid OR current_setting('app.system', true) = 'on'";

    public function getDescription(): string
    {
        return 'Background CSV imports';
    }

    public function up(Schema $schema): void
    {
        // A workspace's, as every table of a workspace: its workspace_id set by the trigger, read
        // through row-level security; the queries name the creator (DbalImportJobStore). Kept out
        // of the ORM mapping (doctrine.dbal.schema_filter).
        $this->addSql(<<<'SQL'
            CREATE TABLE core.record_import (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                object VARCHAR(63) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                separator VARCHAR(1) NOT NULL,
                mapping JSONB NOT NULL,
                key_field VARCHAR(63) DEFAULT NULL,
                matches JSONB NOT NULL,
                created_by VARCHAR(180) NOT NULL,
                locale VARCHAR(10) NOT NULL,
                status VARCHAR(20) NOT NULL,
                stop_requested BOOLEAN DEFAULT false NOT NULL,
                total_rows INT NOT NULL,
                processed INT DEFAULT 0 NOT NULL,
                created INT DEFAULT 0 NOT NULL,
                updated INT DEFAULT 0 NOT NULL,
                reasons JSONB DEFAULT '[]' NOT NULL,
                reason_count INT DEFAULT 0 NOT NULL,
                stopped_at_line INT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_record_import_creator ON core.record_import (workspace_id, created_by, object, created_at DESC)');
        $this->addSql('ALTER TABLE core.record_import ADD CONSTRAINT fk_record_import_workspace FOREIGN KEY (workspace_id) REFERENCES core.workspace (id) ON DELETE CASCADE');
        $this->addSql('CREATE TRIGGER set_workspace_id BEFORE INSERT ON core.record_import FOR EACH ROW EXECUTE FUNCTION core.set_workspace_id()');
        $this->addSql('ALTER TABLE core.record_import ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE core.record_import FORCE ROW LEVEL SECURITY');
        $this->addSql(\sprintf('CREATE POLICY workspace_isolation ON core.record_import USING (%1$s) WITH CHECK (%1$s)', self::CONDITION));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core.record_import');
    }
}
