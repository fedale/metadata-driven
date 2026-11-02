<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An archived field keeps its column out of the application';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.metadata_field ADD is_archived BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.metadata_field DROP is_archived');
    }
}
