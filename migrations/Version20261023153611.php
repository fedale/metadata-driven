<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023153611 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the composite forms (core.metadata_form)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE core.metadata_form (name VARCHAR(50) NOT NULL, label VARCHAR(100) NOT NULL, source TEXT NOT NULL, definition JSONB NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_metadata_form_name ON core.metadata_form (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core.metadata_form');
    }
}
