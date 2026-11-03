<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename core.user_profile to core.app_user_profile, in line with core.app_user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.user_profile RENAME TO app_user_profile');
        // Doctrine derives index and foreign key names from the table name: rename them too, or the next diff would recreate them
        $this->addSql('ALTER INDEX core.user_profile_pkey RENAME TO app_user_profile_pkey');
        $this->addSql('ALTER INDEX core.uniq_1388a8dca76ed395 RENAME TO UNIQ_9D0B8EB0A76ED395');
        $this->addSql('ALTER TABLE core.app_user_profile RENAME CONSTRAINT fk_1388a8dca76ed395 TO FK_9D0B8EB0A76ED395');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.app_user_profile RENAME CONSTRAINT FK_9D0B8EB0A76ED395 TO FK_1388A8DCA76ED395');
        $this->addSql('ALTER INDEX core.UNIQ_9D0B8EB0A76ED395 RENAME TO UNIQ_1388A8DCA76ED395');
        $this->addSql('ALTER INDEX core.app_user_profile_pkey RENAME TO user_profile_pkey');
        $this->addSql('ALTER TABLE core.app_user_profile RENAME TO user_profile');
    }
}
