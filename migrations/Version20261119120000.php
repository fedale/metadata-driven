<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Part E of docs/internal/notifications-plan.md: the emails the workflows send, counted per
 * workspace and day next to their steps, against the plan's emails_per_month.
 */
final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The emails of the workflows, in their usage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.workflow_usage ADD emails INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.workflow_usage DROP emails');
    }
}
