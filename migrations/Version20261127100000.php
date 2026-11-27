<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Why a workflow run, or one of its steps, failed: a message (key, English text, parameters),
 * shown in the language of whoever reads the run. An error written before is a JSON string:
 * read as it was, in English.
 */
final class Version20261127100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The errors of workflow runs and their steps as messages (jsonb)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.workflow_run ALTER error TYPE JSONB USING to_jsonb(error)');
        $this->addSql('ALTER TABLE core.workflow_run_step ALTER error TYPE JSONB USING to_jsonb(error)');
    }

    public function down(Schema $schema): void
    {
        // A message goes back as its English text, without its parameters filled in.
        $this->addSql("ALTER TABLE core.workflow_run ALTER error TYPE TEXT USING CASE jsonb_typeof(error) WHEN 'string' THEN error #>> '{}' ELSE error ->> 'text' END");
        $this->addSql("ALTER TABLE core.workflow_run_step ALTER error TYPE TEXT USING CASE jsonb_typeof(error) WHEN 'string' THEN error #>> '{}' ELSE error ->> 'text' END");
    }
}
