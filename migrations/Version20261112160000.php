<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The row-level security policies of Version20261112150000 failed open: a session without a
 * tenant saw every tenant's rows, so one that lost its tenant (a connection opened again, a
 * switch that didn't happen) would have read them all. Now a session sees every row only with
 * the landlord's explicit mark, app.landlord = 'on' (TenantSwitcher::landlord()); without a
 * tenant and without the mark, none.
 */
final class Version20261112160000 extends AbstractMigration
{
    private const array POLICY_TABLES = [
        'metadata_object',
        'metadata_field',
        'metadata_view',
        'metadata_form',
        'metadata_translation',
        'metadata_state',
        'schema_change_log',
        'template_installation',
        'template_item',
        'template_source',
    ];

    private const string CURRENT_TENANT = "NULLIF(current_setting('app.tenant_id', true), '')::uuid";

    public function getDescription(): string
    {
        return 'Row-level security: every tenant\'s rows for the landlord\'s explicit mark only, none for a session without a tenant';
    }

    public function up(Schema $schema): void
    {
        $this->recreate(\sprintf("tenant_id = %s OR current_setting('app.landlord', true) = 'on'", self::CURRENT_TENANT));
    }

    public function down(Schema $schema): void
    {
        $this->recreate(\sprintf("NULLIF(current_setting('app.tenant_id', true), '') IS NULL OR tenant_id = %s", self::CURRENT_TENANT));
    }

    private function recreate(string $condition): void
    {
        foreach (self::POLICY_TABLES as $table) {
            $this->addSql(\sprintf('DROP POLICY tenant_isolation ON core.%s', $table));
            $this->addSql(\sprintf('CREATE POLICY tenant_isolation ON core.%1$s USING (%2$s) WITH CHECK (%2$s)', $table, $condition));
        }
    }
}
