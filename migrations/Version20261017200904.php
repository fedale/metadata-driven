<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017200904 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the data schema for record tables and the schema change log';
    }

    public function up(Schema $schema): void
    {
        // Record tables are created at runtime by the Schema module, not by migrations.
        $this->addSql('CREATE SCHEMA data');
        $this->addSql('CREATE TABLE core.schema_change_log (object_name VARCHAR(50) NOT NULL, table_name VARCHAR(120) NOT NULL, statements JSONB NOT NULL, skipped JSONB NOT NULL, applied_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_schema_change_log_object ON core.schema_change_log (object_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core.schema_change_log');
        // Fails while record tables exist, on purpose: they hold user data.
        $this->addSql('DROP SCHEMA data');
    }
}
