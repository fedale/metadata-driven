<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Part D of docs/internal/notifications-plan.md: the notifications of the bell, and the choice
 * of getting them by email too.
 */
final class Version20261119110000 extends AbstractMigration
{
    private const string CONDITION = "workspace_id = NULLIF(current_setting('app.workspace_id', true), '')::uuid OR current_setting('app.system', true) = 'on'";

    public function getDescription(): string
    {
        return 'Notifications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.app_user_profile ADD notify_by_email BOOLEAN DEFAULT false NOT NULL');

        // A workspace's, as every table of a workspace: its workspace_id set by the trigger, read
        // through row-level security; the queries name the recipient (DbalNotificationStore).
        // Kept out of the ORM mapping (doctrine.dbal.schema_filter).
        $this->addSql(<<<'SQL'
            CREATE TABLE core.notification (
                id UUID NOT NULL,
                workspace_id UUID NOT NULL,
                recipient VARCHAR(180) NOT NULL,
                title VARCHAR(200) NOT NULL,
                body TEXT DEFAULT NULL,
                link VARCHAR(500) DEFAULT NULL,
                origin VARCHAR(200) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_notification_recipient ON core.notification (workspace_id, recipient, created_at DESC)');
        $this->addSql('CREATE INDEX idx_notification_unread ON core.notification (workspace_id, recipient) WHERE read_at IS NULL');
        $this->addSql('ALTER TABLE core.notification ADD CONSTRAINT fk_notification_workspace FOREIGN KEY (workspace_id) REFERENCES core.workspace (id) ON DELETE CASCADE');
        $this->addSql('CREATE TRIGGER set_workspace_id BEFORE INSERT ON core.notification FOR EACH ROW EXECUTE FUNCTION core.set_workspace_id()');
        $this->addSql('ALTER TABLE core.notification ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE core.notification FORCE ROW LEVEL SECURITY');
        $this->addSql(\sprintf('CREATE POLICY workspace_isolation ON core.notification USING (%1$s) WITH CHECK (%1$s)', self::CONDITION));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core.notification');
        $this->addSql('ALTER TABLE core.app_user_profile DROP notify_by_email');
    }
}
