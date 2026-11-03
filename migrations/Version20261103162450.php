<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103162450 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The metadata of the files of the records; their bytes are in the record_files storage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE core.file (storage_key VARCHAR(100) NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(127) NOT NULL, size BIGINT NOT NULL, sha256 CHAR(64) NOT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_file_storage_key ON core.file (storage_key)');
        $this->addSql('CREATE INDEX idx_file_sha256 ON core.file (sha256)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core.file');
    }
}
