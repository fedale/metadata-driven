<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106081911 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The templates installed, with the documents they were installed from';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE core.template_installation (name VARCHAR(100) NOT NULL, version VARCHAR(50) NOT NULL, source VARCHAR(1000) NOT NULL, snapshot JSONB NOT NULL, installed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, upgraded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_template_installation_name ON core.template_installation (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core.template_installation');
    }
}
