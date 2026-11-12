<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 6 of docs/internal/multi-tenancy-plan.md: the indexes templates are downloaded from may
 * be offered to every tenant. Such a row has no tenant (tenant:template:repo, as the landlord);
 * every tenant reads it, none writes it.
 */
final class Version20261112190000 extends AbstractMigration
{
    private const string CURRENT_TENANT = "NULLIF(current_setting('app.tenant_id', true), '')::uuid";
    private const string LANDLORD = "current_setting('app.landlord', true) = 'on'";

    public function getDescription(): string
    {
        return 'Template indexes offered to every tenant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.template_source ALTER tenant_id DROP NOT NULL');
        // (tenant_id, name) is unique per tenant; NULLs are distinct there, so the shared rows
        // need an index of their own.
        $this->addSql('CREATE UNIQUE INDEX uniq_template_source_shared_name ON core.template_source (name) WHERE tenant_id IS NULL');

        $own = \sprintf('tenant_id = %s OR %s', self::CURRENT_TENANT, self::LANDLORD);
        $this->addSql('DROP POLICY tenant_isolation ON core.template_source');
        $this->addSql(\sprintf('CREATE POLICY tenant_read ON core.template_source FOR SELECT USING (tenant_id IS NULL OR %s)', $own));
        $this->addSql(\sprintf('CREATE POLICY tenant_insert ON core.template_source FOR INSERT WITH CHECK (%s)', $own));
        $this->addSql(\sprintf('CREATE POLICY tenant_update ON core.template_source FOR UPDATE USING (%1$s) WITH CHECK (%1$s)', $own));
        $this->addSql(\sprintf('CREATE POLICY tenant_delete ON core.template_source FOR DELETE USING (%s)', $own));
    }

    public function down(Schema $schema): void
    {
        foreach (['tenant_read', 'tenant_insert', 'tenant_update', 'tenant_delete'] as $policy) {
            $this->addSql(\sprintf('DROP POLICY %s ON core.template_source', $policy));
        }
        $this->addSql(\sprintf(
            'CREATE POLICY tenant_isolation ON core.template_source USING (tenant_id = %1$s OR %2$s) WITH CHECK (tenant_id = %1$s OR %2$s)',
            self::CURRENT_TENANT,
            self::LANDLORD,
        ));
        $this->addSql('DROP INDEX core.uniq_template_source_shared_name');
        // The shared indexes have no tenant to go to.
        $this->addSql('DELETE FROM core.template_source WHERE tenant_id IS NULL');
        $this->addSql('ALTER TABLE core.template_source ALTER tenant_id SET NOT NULL');
    }
}
