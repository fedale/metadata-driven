<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103100000 extends AbstractMigration
{
    private const array TABLES = [
        'auth_assignment',
        'auth_item',
        'auth_item_child',
        'auth_rule',
        'setting',
        'setting_scoped',
    ];

    public function getDescription(): string
    {
        return 'Move the bundle tables (rbac, settings) from public to core';
    }

    public function up(Schema $schema): void
    {
        // IF EXISTS: on a fresh database the search_path already created them in core.
        // Indexes, constraints and identity sequences move with their table.
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE IF EXISTS public.%s SET SCHEMA core', $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE IF EXISTS core.%s SET SCHEMA public', $table));
        }
    }
}
