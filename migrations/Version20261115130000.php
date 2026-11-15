<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 5 of docs/internal/vocabulary-rename-plan.md: a view's HTML layout or grid YAML is its
 * source, as in the package format v2, no longer its template.
 */
final class Version20261115130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the template of the views to source';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.metadata_view RENAME COLUMN template TO source');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.metadata_view RENAME COLUMN source TO template');
    }
}
