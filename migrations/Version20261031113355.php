<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261031113355 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compiled definitions of the grid views (core.metadata_view.definition)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.metadata_view ADD definition JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.metadata_view DROP definition');
    }
}
