<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A workspace's secrets for its workflows' HTTP requests: their names, their values encrypted
 * (WORKFLOW_SECRETS_KEY), read with DBAL and hidden from the schema tools (doctrine.yaml).
 */
final class Version20261116140000 extends AbstractMigration
{
    private const string CONDITION = "workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::uuid OR current_setting('app.system', true) = 'on'";

    public function getDescription(): string
    {
        return 'The secrets of the workflows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE core.workflow_secret (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                name VARCHAR(64) NOT NULL,
                value TEXT NOT NULL,
                updated_by VARCHAR(180) DEFAULT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_workflow_secret_name ON core.workflow_secret (workspace_id, name)');
        $this->addSql('ALTER TABLE core.workflow_secret ADD CONSTRAINT fk_workflow_secret_workspace FOREIGN KEY (workspace_id) REFERENCES core.workspace (id) ON DELETE CASCADE');
        $this->addSql('CREATE TRIGGER set_workspace_id BEFORE INSERT ON core.workflow_secret FOR EACH ROW EXECUTE FUNCTION core.set_workspace_id()');
        $this->addSql('ALTER TABLE core.workflow_secret ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE core.workflow_secret FORCE ROW LEVEL SECURITY');
        $this->addSql(\sprintf('CREATE POLICY workspace_isolation ON core.workflow_secret USING (%1$s) WITH CHECK (%1$s)', self::CONDITION));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core.workflow_secret');
    }
}
