<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Part C of docs/internal/notifications-plan.md: a password reset by email.
 */
final class Version20261119100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Password resets';
    }

    public function up(Schema $schema): void
    {
        // Accounts are global, so are their resets: no workspace, no row-level security, every
        // query naming its account or its token; kept out of the ORM mapping
        // (doctrine.dbal.schema_filter). The token is in the link only: its SHA-256 is stored.
        $this->addSql('CREATE TABLE core.password_reset (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            token_hash CHAR(64) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY (id),
            CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES core.app_user (id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_password_reset_token ON core.password_reset (token_hash)');
        $this->addSql('CREATE INDEX idx_password_reset_user ON core.password_reset (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core.password_reset');
    }
}
