<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261022193518 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the views of the objects (core.metadata_view)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE core.metadata_view (kind VARCHAR(20) NOT NULL, name VARCHAR(50) NOT NULL, template TEXT NOT NULL, is_default BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, object_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_metadata_view_object_kind_name ON core.metadata_view (object_id, kind, name)');
        $this->addSql('CREATE INDEX IDX_DAFA652B232D562B ON core.metadata_view (object_id)');
        $this->addSql('ALTER TABLE core.metadata_view ADD CONSTRAINT FK_DAFA652B232D562B FOREIGN KEY (object_id) REFERENCES core.metadata_object (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.metadata_view DROP CONSTRAINT FK_DAFA652B232D562B');
        $this->addSql('DROP TABLE core.metadata_view');
    }
}
