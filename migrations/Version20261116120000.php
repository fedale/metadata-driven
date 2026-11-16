<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A run that waits (the wait step): when it resumes, and from which of its definition's steps.
 */
final class Version20261116120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The workflows\' runs that wait';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.workflow_run ADD resume_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL, ADD resume_step INT DEFAULT NULL');
        $this->addSql("CREATE INDEX idx_workflow_run_waiting ON core.workflow_run (resume_at) WHERE status = 'waiting'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX core.idx_workflow_run_waiting');
        $this->addSql('ALTER TABLE core.workflow_run DROP resume_at, DROP resume_step');
    }
}
