<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 3 of docs/internal/multi-tenancy-plan.md: users are global, and a membership says which
 * tenants each may sign in to. Every existing user becomes a member of the first tenant.
 */
final class Version20261112170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Which tenants each user may sign in to';
    }

    public function up(Schema $schema): void
    {
        // The landlord's, as core.tenant: no row-level security (a user's tenants are listed
        // from any of them, for the tenant switcher), and kept out of the ORM mapping
        // (doctrine.dbal.schema_filter).
        $this->addSql('CREATE TABLE core.tenant_membership (
            tenant_id UUID NOT NULL,
            user_id UUID NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (tenant_id, user_id),
            CONSTRAINT fk_tenant_membership_tenant FOREIGN KEY (tenant_id) REFERENCES core.tenant (id) ON DELETE CASCADE,
            CONSTRAINT fk_tenant_membership_user FOREIGN KEY (user_id) REFERENCES core.app_user (id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE INDEX idx_tenant_membership_user ON core.tenant_membership (user_id)');
        $this->addSql('INSERT INTO core.tenant_membership (tenant_id, user_id)
            SELECT (SELECT id FROM core.tenant ORDER BY created_at, id LIMIT 1), id FROM core.app_user');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE core.tenant_membership');
    }
}
