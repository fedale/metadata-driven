<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 6 of docs/internal/vocabulary-rename-plan.md: the catalog's repository is now
 * fedale/metadata-driven-packages, and GitHub Pages does not redirect the old address. The
 * addresses stored with it follow: where each installation upgrades from, and the indexes added
 * by hand that point at the catalog.
 */
final class Version20261115150000 extends AbstractMigration
{
    private const string OLD = 'https://fedale.github.io/metadata-driven-templates/';
    private const string NEW = 'https://fedale.github.io/metadata-driven-packages/';

    public function getDescription(): string
    {
        return 'Point the stored addresses at the renamed catalog';
    }

    public function up(Schema $schema): void
    {
        $this->move(self::OLD, self::NEW);
    }

    public function down(Schema $schema): void
    {
        $this->move(self::NEW, self::OLD);
    }

    /**
     * The migrations run in the system session, which sees the rows of every workspace.
     */
    private function move(string $from, string $to): void
    {
        $this->addSql('UPDATE core.package_installation SET source = ? || substr(source, ?) WHERE starts_with(source, ?)', [$to, \strlen($from) + 1, $from]);
        $this->addSql('UPDATE core.package_source SET url = ? || substr(url, ?) WHERE starts_with(url, ?)', [$to, \strlen($from) + 1, $from]);
    }
}
