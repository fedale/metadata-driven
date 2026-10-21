<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021040733 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users, their profiles, and the RBAC tables of fedale/rbac-bundle';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE core.app_user (email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_app_user_email ON core.app_user (email)');
        $this->addSql('CREATE TABLE auth_assignment (user_id VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, item_name VARCHAR(64) NOT NULL, PRIMARY KEY (item_name, user_id))');
        $this->addSql('CREATE INDEX idx_auth_assignment_user ON auth_assignment (user_id)');
        $this->addSql('CREATE INDEX IDX_2EC0490E96133AFD ON auth_assignment (item_name)');
        $this->addSql('CREATE TABLE auth_item (name VARCHAR(64) NOT NULL, type VARCHAR(32) NOT NULL, description TEXT DEFAULT NULL, data JSON DEFAULT \'[]\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, rule_name VARCHAR(64) DEFAULT NULL, PRIMARY KEY (name))');
        $this->addSql('CREATE INDEX idx_auth_item_type ON auth_item (type)');
        $this->addSql('CREATE INDEX IDX_313DC5AADE72171 ON auth_item (rule_name)');
        $this->addSql('CREATE TABLE auth_item_child (parent VARCHAR(64) NOT NULL, child VARCHAR(64) NOT NULL, PRIMARY KEY (parent, child))');
        $this->addSql('CREATE INDEX IDX_1611424D3D8E604F ON auth_item_child (parent)');
        $this->addSql('CREATE INDEX IDX_1611424D22B35429 ON auth_item_child (child)');
        $this->addSql('CREATE TABLE auth_rule (name VARCHAR(64) NOT NULL, service_id VARCHAR(255) DEFAULT NULL, expression TEXT DEFAULT NULL, data JSON DEFAULT \'[]\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (name))');
        $this->addSql('CREATE TABLE core.user_profile (first_name VARCHAR(100) DEFAULT NULL, last_name VARCHAR(100) DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1388A8DCA76ED395 ON core.user_profile (user_id)');
        $this->addSql('ALTER TABLE auth_assignment ADD CONSTRAINT FK_2EC0490E96133AFD FOREIGN KEY (item_name) REFERENCES auth_item (name) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE auth_item ADD CONSTRAINT FK_313DC5AADE72171 FOREIGN KEY (rule_name) REFERENCES auth_rule (name) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE auth_item_child ADD CONSTRAINT FK_1611424D3D8E604F FOREIGN KEY (parent) REFERENCES auth_item (name) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE auth_item_child ADD CONSTRAINT FK_1611424D22B35429 FOREIGN KEY (child) REFERENCES auth_item (name) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE core.user_profile ADD CONSTRAINT FK_1388A8DCA76ED395 FOREIGN KEY (user_id) REFERENCES core.app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auth_assignment DROP CONSTRAINT FK_2EC0490E96133AFD');
        $this->addSql('ALTER TABLE auth_item DROP CONSTRAINT FK_313DC5AADE72171');
        $this->addSql('ALTER TABLE auth_item_child DROP CONSTRAINT FK_1611424D3D8E604F');
        $this->addSql('ALTER TABLE auth_item_child DROP CONSTRAINT FK_1611424D22B35429');
        $this->addSql('ALTER TABLE core.user_profile DROP CONSTRAINT FK_1388A8DCA76ED395');
        $this->addSql('DROP TABLE core.app_user');
        $this->addSql('DROP TABLE auth_assignment');
        $this->addSql('DROP TABLE auth_item');
        $this->addSql('DROP TABLE auth_item_child');
        $this->addSql('DROP TABLE auth_rule');
        $this->addSql('DROP TABLE core.user_profile');
    }
}
