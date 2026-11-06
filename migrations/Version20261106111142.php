<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106111142 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The indexes of templates this instance looks into';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE core.template_source (name VARCHAR(50) NOT NULL, url VARCHAR(1000) NOT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_template_source_name ON core.template_source (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core.template_source');
    }
}
