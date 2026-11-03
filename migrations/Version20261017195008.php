<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017195008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the core schema with object and field definitions';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // make db creates the schema before migrating: core.doctrine_migration_versions has to live somewhere
        $this->addSql('CREATE SCHEMA IF NOT EXISTS core');
        $this->addSql('CREATE TABLE core.metadata_field (name VARCHAR(50) NOT NULL, column_name VARCHAR(63) NOT NULL, type VARCHAR(50) NOT NULL, label VARCHAR(100) NOT NULL, is_required BOOLEAN NOT NULL, is_unique BOOLEAN NOT NULL, settings JSONB NOT NULL, position INT NOT NULL, is_system BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, object_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_metadata_field_object_name ON core.metadata_field (object_id, name)');
        $this->addSql('CREATE INDEX IDX_FD6D15F1232D562B ON core.metadata_field (object_id)');
        $this->addSql('CREATE TABLE core.metadata_object (name VARCHAR(50) NOT NULL, table_name VARCHAR(63) NOT NULL, label_singular VARCHAR(100) NOT NULL, label_plural VARCHAR(100) NOT NULL, icon VARCHAR(50) DEFAULT NULL, is_system BOOLEAN NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_metadata_object_name ON core.metadata_object (name)');
        $this->addSql('CREATE UNIQUE INDEX uniq_metadata_object_table_name ON core.metadata_object (table_name)');
        $this->addSql('ALTER TABLE core.metadata_field ADD CONSTRAINT FK_FD6D15F1232D562B FOREIGN KEY (object_id) REFERENCES core.metadata_object (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE core.metadata_field DROP CONSTRAINT FK_FD6D15F1232D562B');
        $this->addSql('DROP TABLE core.metadata_field');
        $this->addSql('DROP TABLE core.metadata_object');
        // The schema stays: it holds core.doctrine_migration_versions
    }
}
