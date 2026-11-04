<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104055054 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The field sets an object keeps unique together';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.metadata_object ADD unique_together JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.metadata_object DROP unique_together');
    }
}
