<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020190848 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the label field of objects (the field naming their records in relations)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.metadata_object ADD label_field VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.metadata_object DROP label_field');
    }
}
