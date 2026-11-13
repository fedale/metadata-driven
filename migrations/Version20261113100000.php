<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Part A2 of docs/internal/tenant-admin-ui-plan.md: invitations to a tenant, accepted by whoever
 * the email belongs to, so that a tenant's admin no longer learns whether an email has an
 * account.
 */
final class Version20261113100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invitations to a tenant';
    }

    public function up(Schema $schema): void
    {
        // The landlord's, as core.tenant_membership: no row-level security, every query naming
        // its tenant; kept out of the ORM mapping (doctrine.dbal.schema_filter). The token is
        // shown once and never stored: its SHA-256 is.
        $this->addSql('CREATE TABLE core.tenant_invitation (
            id UUID NOT NULL,
            tenant_id UUID NOT NULL,
            email VARCHAR(180) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            invited_by VARCHAR(180) NOT NULL,
            keep_assignments BOOLEAN NOT NULL,
            failed_attempts INT DEFAULT 0 NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY (id),
            CONSTRAINT fk_tenant_invitation_tenant FOREIGN KEY (tenant_id) REFERENCES core.tenant (id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_tenant_invitation_token ON core.tenant_invitation (token_hash)');
        $this->addSql('CREATE INDEX idx_tenant_invitation_tenant ON core.tenant_invitation (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core.tenant_invitation');
    }
}
