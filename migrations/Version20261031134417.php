<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261031134417 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Translations of the metadata (core.metadata_translation) and the language of each user (core.user_profile.locale)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE core.metadata_translation (locale VARCHAR(10) NOT NULL, message_key VARCHAR(255) NOT NULL, text TEXT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_metadata_translation_locale_key ON core.metadata_translation (locale, message_key)');
        $this->addSql('ALTER TABLE core.user_profile ADD locale VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core.metadata_translation');
        $this->addSql('ALTER TABLE core.user_profile DROP locale');
    }
}
