<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105164722 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The ids the objects, fields, views and forms of the app have in templates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE core.template_item (template VARCHAR(100) NOT NULL, kind VARCHAR(20) NOT NULL, item_id VARCHAR(200) NOT NULL, local_id UUID NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_template_item_item_id ON core.template_item (template, kind, item_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_template_item_local_id ON core.template_item (template, local_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core.template_item');
    }
}
