<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * fedale/rbac-bundle 1.2 maps auth_assignment.tenant_id, in the primary key and in
 * idx_auth_assignment_user: added to every RBAC schema, the tenants' (core.tenant.rbac_schema)
 * and the one of the sessions of no tenant. This app has tables per tenant, so every
 * assignment stays in the bundle's 'global' tenant, the column's default.
 *
 * That schema is renamed from landlord_rbac to no_tenant_rbac: it serves the sessions with no
 * tenant at all, not only the landlord's, and grants nothing ('global' would suggest roles
 * shared by every tenant).
 */
final class Version20261114100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'auth_assignment.tenant_id in every RBAC schema (fedale/rbac-bundle 1.2); landlord_rbac renamed no_tenant_rbac';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER SCHEMA landlord_rbac RENAME TO no_tenant_rbac');
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                rbac text;
            BEGIN
                FOR rbac IN SELECT rbac_schema FROM core.tenant UNION ALL SELECT 'no_tenant_rbac' LOOP
                    EXECUTE format('ALTER TABLE %I.auth_assignment ADD tenant_id VARCHAR(64) DEFAULT ''global'' NOT NULL', rbac);
                    EXECUTE format('ALTER TABLE %I.auth_assignment DROP CONSTRAINT auth_assignment_pkey', rbac);
                    EXECUTE format('ALTER TABLE %I.auth_assignment ADD PRIMARY KEY (item_name, user_id, tenant_id)', rbac);
                    EXECUTE format('DROP INDEX %I.idx_auth_assignment_user', rbac);
                    EXECUTE format('CREATE INDEX idx_auth_assignment_user ON %I.auth_assignment (user_id, tenant_id)', rbac);
                END LOOP;
            END
            $$
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Every assignment of this app is in 'global'; any other would collide on the old key.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                rbac text;
            BEGIN
                FOR rbac IN SELECT rbac_schema FROM core.tenant UNION ALL SELECT 'no_tenant_rbac' LOOP
                    EXECUTE format('DELETE FROM %I.auth_assignment WHERE tenant_id <> ''global''', rbac);
                    EXECUTE format('DROP INDEX %I.idx_auth_assignment_user', rbac);
                    EXECUTE format('CREATE INDEX idx_auth_assignment_user ON %I.auth_assignment (user_id)', rbac);
                    EXECUTE format('ALTER TABLE %I.auth_assignment DROP CONSTRAINT auth_assignment_pkey', rbac);
                    EXECUTE format('ALTER TABLE %I.auth_assignment DROP tenant_id', rbac);
                    EXECUTE format('ALTER TABLE %I.auth_assignment ADD PRIMARY KEY (item_name, user_id)', rbac);
                END LOOP;
            END
            $$
            SQL);
        $this->addSql('ALTER SCHEMA no_tenant_rbac RENAME TO landlord_rbac');
    }
}
