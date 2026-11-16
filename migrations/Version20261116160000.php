<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The address of a workflow on a webhook: its token, hashed (the address is shown once, when
 * made). Read with DBAL, hidden from the schema tools (doctrine.yaml).
 */
final class Version20261116160000 extends AbstractMigration
{
    private const string CONDITION = "workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::uuid OR current_setting('app.system', true) = 'on'";

    public function getDescription(): string
    {
        return 'The tokens of the workflows on a webhook';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE core.workflow_webhook (workflow_id UUID NOT NULL, workspace_id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, made_by VARCHAR(180) DEFAULT NULL, made_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (workflow_id))');
        $this->addSql('ALTER TABLE core.workflow_webhook ADD CONSTRAINT fk_workflow_webhook_workflow FOREIGN KEY (workflow_id) REFERENCES core.workflow (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE core.workflow_webhook ADD CONSTRAINT fk_workflow_webhook_workspace FOREIGN KEY (workspace_id) REFERENCES core.workspace (id) ON DELETE CASCADE');
        $this->addSql('CREATE TRIGGER set_workspace_id BEFORE INSERT ON core.workflow_webhook FOR EACH ROW EXECUTE FUNCTION core.set_workspace_id()');
        $this->addSql('ALTER TABLE core.workflow_webhook ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE core.workflow_webhook FORCE ROW LEVEL SECURITY');
        $this->addSql(\sprintf('CREATE POLICY workspace_isolation ON core.workflow_webhook USING (%1$s) WITH CHECK (%1$s)', self::CONDITION));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core.workflow_webhook');
    }
}
