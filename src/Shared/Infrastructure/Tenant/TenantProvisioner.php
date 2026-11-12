<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant;

use App\Shared\Tenant\Tenant;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Creates, suspends, resumes and deletes tenants, in the landlord's session only
 * (TenantSwitcher::landlord()): where row-level security applies, a tenant's session may not
 * write another tenant's rows.
 *
 * A tenant is its row of core.tenant, its row of core.metadata_state, its data schema (empty:
 * the Schema module fills it from the tenant's metadata) and its RBAC schema with
 * fedale/rbac-bundle's tables. It starts with no objects, members or permissions: tenant:create
 * adds its permissions, its first admin and a template if asked.
 */
final class TenantProvisioner
{
    private const string SLUG = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    /**
     * fedale/rbac-bundle's tables, as migration Version20261021040733 created them, in the
     * schema %1$s. When the bundle's mapping changes, a migration changes every tenant's
     * schema, and this with it.
     */
    private const array RBAC_DDL = [
        'CREATE TABLE %1$s.auth_rule (name VARCHAR(64) NOT NULL, service_id VARCHAR(255) DEFAULT NULL, expression TEXT DEFAULT NULL, data JSON DEFAULT \'[]\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (name))',
        'CREATE TABLE %1$s.auth_item (name VARCHAR(64) NOT NULL, type VARCHAR(32) NOT NULL, description TEXT DEFAULT NULL, data JSON DEFAULT \'[]\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, rule_name VARCHAR(64) DEFAULT NULL, PRIMARY KEY (name))',
        'CREATE INDEX idx_auth_item_type ON %1$s.auth_item (type)',
        'CREATE INDEX IDX_313DC5AADE72171 ON %1$s.auth_item (rule_name)',
        'CREATE TABLE %1$s.auth_item_child (parent VARCHAR(64) NOT NULL, child VARCHAR(64) NOT NULL, PRIMARY KEY (parent, child))',
        'CREATE INDEX IDX_1611424D3D8E604F ON %1$s.auth_item_child (parent)',
        'CREATE INDEX IDX_1611424D22B35429 ON %1$s.auth_item_child (child)',
        'CREATE TABLE %1$s.auth_assignment (user_id VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, item_name VARCHAR(64) NOT NULL, PRIMARY KEY (item_name, user_id))',
        'CREATE INDEX idx_auth_assignment_user ON %1$s.auth_assignment (user_id)',
        'CREATE INDEX IDX_2EC0490E96133AFD ON %1$s.auth_assignment (item_name)',
        'ALTER TABLE %1$s.auth_assignment ADD CONSTRAINT FK_2EC0490E96133AFD FOREIGN KEY (item_name) REFERENCES %1$s.auth_item (name) ON DELETE CASCADE NOT DEFERRABLE',
        'ALTER TABLE %1$s.auth_item ADD CONSTRAINT FK_313DC5AADE72171 FOREIGN KEY (rule_name) REFERENCES %1$s.auth_rule (name) ON DELETE SET NULL NOT DEFERRABLE',
        'ALTER TABLE %1$s.auth_item_child ADD CONSTRAINT FK_1611424D3D8E604F FOREIGN KEY (parent) REFERENCES %1$s.auth_item (name) ON DELETE CASCADE NOT DEFERRABLE',
        'ALTER TABLE %1$s.auth_item_child ADD CONSTRAINT FK_1611424D22B35429 FOREIGN KEY (child) REFERENCES %1$s.auth_item (name) ON DELETE CASCADE NOT DEFERRABLE',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly TenantRegistry $registry,
        private readonly ConnectionCurrentTenant $current,
    ) {
    }

    public function create(string $slug, string $name): Tenant
    {
        $this->assertLandlord();
        if (1 !== preg_match(self::SLUG, $slug)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is no tenant slug: lower case letters, digits and dashes, 63 at most.', $slug));
        }
        if (null !== $this->registry->bySlug($slug)) {
            throw new \InvalidArgumentException(\sprintf('There is a tenant "%s" already.', $slug));
        }

        // Named after the id, not the slug, which may change: the random end of a UUID v7.
        $id = Uuid::v7()->toRfc4122();
        $base = 't_'.substr(str_replace('-', '', $id), -12);
        $dataSchema = $base.'_data';
        $rbacSchema = $base.'_rbac';

        $this->connection->transactional(function () use ($id, $slug, $name, $dataSchema, $rbacSchema): void {
            $this->connection->executeStatement(
                'INSERT INTO core.tenant (id, slug, name, data_schema, rbac_schema) VALUES (?, ?, ?, ?, ?)',
                [$id, $slug, $name, $dataSchema, $rbacSchema],
            );
            $this->connection->executeStatement('INSERT INTO core.metadata_state (tenant_id) VALUES (?)', [$id]);
            $this->connection->executeStatement(\sprintf('CREATE SCHEMA %s', $dataSchema));
            $this->connection->executeStatement(\sprintf('CREATE SCHEMA %s', $rbacSchema));
            foreach (self::RBAC_DDL as $statement) {
                $this->connection->executeStatement(\sprintf($statement, $rbacSchema));
            }
        });

        return $this->registry->byId($id) ?? throw new \LogicException(\sprintf('Tenant "%s" was not created.', $slug));
    }

    /**
     * A suspended tenant answers 404 to every request, and its commands refuse to run; its data
     * stays as it is.
     */
    public function suspend(Tenant $tenant): void
    {
        $this->setStatus($tenant, 'suspended');
    }

    public function resume(Tenant $tenant): void
    {
        $this->setStatus($tenant, 'active');
    }

    /**
     * Drops the tenant's schemas (its records, its roles) and deletes its rows of every core
     * table (the foreign keys to core.tenant cascade), in one transaction. The bytes of its
     * files stay until metadata:files:gc runs for any tenant: no row holds them any more.
     */
    public function delete(Tenant $tenant): void
    {
        $this->assertLandlord();
        $this->connection->transactional(function () use ($tenant): void {
            // The schemas first: the record tables reference core.file, RESTRICT.
            $this->connection->executeStatement(\sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $tenant->dataSchema));
            $this->connection->executeStatement(\sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $tenant->rbacSchema));
            $this->connection->executeStatement('DELETE FROM core.tenant WHERE id = ?', [$tenant->id]);
        });
    }

    private function setStatus(Tenant $tenant, string $status): void
    {
        $this->assertLandlord();
        $this->connection->executeStatement('UPDATE core.tenant SET status = ? WHERE id = ?', [$status, $tenant->id]);
    }

    private function assertLandlord(): void
    {
        if ($this->current->isSelected()) {
            throw new \LogicException('Tenants are managed in the landlord\'s session: leave the current tenant first.');
        }
    }
}
