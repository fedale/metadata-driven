<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Part B of docs/internal/tenant-admin-ui-plan.md: the operators, who may enter the console of
 * the installation. Set from the command line only (tenant:operator:grant).
 */
final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The operators of the installation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.app_user ADD is_operator BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.app_user DROP is_operator');
    }
}
