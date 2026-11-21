<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The colour theme a user forces in their profile (light or dark); null: the system's.
 */
final class Version20261121192317 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The theme of the user profile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.app_user_profile ADD theme VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.app_user_profile DROP theme');
    }
}
