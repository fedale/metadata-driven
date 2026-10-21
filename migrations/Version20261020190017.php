<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020190017 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the search configuration of objects and enable pg_trgm for record search indexes';
    }

    public function up(Schema $schema): void
    {
        // Trigram indexes make the case-insensitive LIKE '%term%' of record search use an index.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('ALTER TABLE core.metadata_object ADD search_handler VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE core.metadata_object ADD search_fields JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.metadata_object DROP search_handler');
        $this->addSql('ALTER TABLE core.metadata_object DROP search_fields');
        // Fails while trigram indexes exist, on purpose: drop them with schema:sync first.
        $this->addSql('DROP EXTENSION pg_trgm');
    }
}
