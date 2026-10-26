<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261026192214 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conditions of the fields (core.metadata_field.condition)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.metadata_field ADD condition JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.metadata_field DROP condition');
    }
}
