<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 5 of docs/internal/multi-tenancy-plan.md: each tenant's files are in a directory of its
 * own in the storage, so the storage key grows, the file GC no longer needs the other tenants'
 * rows, and core.file gets the row-level security policy of the other tenant tables
 * (Version20261112160000).
 */
final class Version20261112180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Room for the tenant in the storage key of core.file, and row-level security on it';
    }

    public function up(Schema $schema): void
    {
        // <tenant id>/<aa>/<SHA-256>: 104 characters, more than the 100 of before.
        $this->addSql('ALTER TABLE core.file ALTER storage_key TYPE VARCHAR(120)');
        $condition = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid OR current_setting('app.landlord', true) = 'on'";
        $this->addSql('ALTER TABLE core.file ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE core.file FORCE ROW LEVEL SECURITY');
        $this->addSql(\sprintf('CREATE POLICY tenant_isolation ON core.file USING (%1$s) WITH CHECK (%1$s)', $condition));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY tenant_isolation ON core.file');
        $this->addSql('ALTER TABLE core.file NO FORCE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE core.file DISABLE ROW LEVEL SECURITY');
        // A longer key than 100 characters would refuse the change: run it after moving back.
        $this->addSql('ALTER TABLE core.file ALTER storage_key TYPE VARCHAR(100)');
    }
}
