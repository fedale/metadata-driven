<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A workspace's plan (config/packages/plans.yaml): none for the default plan.
 */
final class Version20261116180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The plan of each workspace';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.workspace ADD plan VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.workspace DROP plan');
    }
}
