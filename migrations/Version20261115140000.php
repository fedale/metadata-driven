<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 5 of docs/internal/vocabulary-rename-plan.md: a template is now a package. The tables
 * core.template_installation, core.template_item and core.template_source follow, with the
 * column template_item.template and every index and constraint named after them. Their
 * policies and triggers name no template, and follow the tables by themselves.
 */
final class Version20261115140000 extends AbstractMigration
{
    private const array TABLES = ['installation', 'item', 'source'];

    public function getDescription(): string
    {
        return 'Rename the templates to packages';
    }

    public function up(Schema $schema): void
    {
        $this->rename('template', 'package');
    }

    public function down(Schema $schema): void
    {
        $this->rename('package', 'template');
    }

    private function rename(string $from, string $to): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE core.%1$s_%3$s RENAME TO %2$s_%3$s', $from, $to, $table));
        }
        $this->addSql(\sprintf('ALTER TABLE core.%2$s_item RENAME COLUMN %1$s TO %2$s', $from, $to));

        // Constraints first (a primary key takes its index along), then the indexes left.
        $this->addSql(\sprintf(<<<'SQL'
            DO $$
            DECLARE
                r record;
            BEGIN
                FOR r IN
                    SELECT c.conname, t.relname
                    FROM pg_constraint c
                    JOIN pg_class t ON t.oid = c.conrelid
                    JOIN pg_namespace n ON n.oid = t.relnamespace
                    WHERE n.nspname = 'core' AND c.conname LIKE '%%%1$s\_%%'
                LOOP
                    EXECUTE format('ALTER TABLE core.%%I RENAME CONSTRAINT %%I TO %%I', r.relname, r.conname, replace(r.conname, '%1$s_', '%2$s_'));
                END LOOP;
                FOR r IN
                    SELECT indexname FROM pg_indexes WHERE schemaname = 'core' AND indexname LIKE '%%%1$s\_%%'
                LOOP
                    EXECUTE format('ALTER INDEX core.%%I RENAME TO %%I', r.indexname, replace(r.indexname, '%1$s_', '%2$s_'));
                END LOOP;
            END
            $$
            SQL, $from, $to));
    }
}
