<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Empty RBAC tables for the sessions of no tenant: the landlord's (the console, the tenant
 * commands, the migrations) and a session that has none. fedale/rbac-bundle reads its tables
 * unqualified, through the search_path; its voter looks up every attribute it is asked about,
 * and without tables of its own there the console failed on an undefined table (a revoked
 * operator, signed out, asked about). Empty, they grant nothing: such a session still holds no
 * tenant's permission.
 *
 * The DDL is App\Shared\Infrastructure\Tenant\TenantProvisioner::RBAC_DDL, copied: a migration
 * must not change when the code does.
 */
final class Version20261113130000 extends AbstractMigration
{
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

    public function getDescription(): string
    {
        return 'Empty RBAC tables for the sessions of no tenant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA landlord_rbac');
        foreach (self::RBAC_DDL as $statement) {
            $this->addSql(\sprintf($statement, 'landlord_rbac'));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SCHEMA landlord_rbac CASCADE');
    }
}
