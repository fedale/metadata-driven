<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 2 of docs/internal/vocabulary-rename-plan.md: the landlord is now the system, and the
 * operators are its system admins. The flag on the user follows, and so does the session setting
 * the row-level security policies read: app.landlord becomes app.system in every policy that
 * names it, whatever its table.
 */
final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the landlord to the system, and the operators to system admins';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.app_user RENAME COLUMN is_operator TO is_system_admin');
        $this->addSql(self::rewritePolicies('app.landlord', 'app.system'));
    }

    public function down(Schema $schema): void
    {
        $this->addSql(self::rewritePolicies('app.system', 'app.landlord'));
        $this->addSql('ALTER TABLE core.app_user RENAME COLUMN is_system_admin TO is_operator');
    }

    /**
     * Alters every policy whose expressions name the setting $from to name $to instead: the
     * expressions are read back from pg_policies, as PostgreSQL deparsed them.
     */
    private static function rewritePolicies(string $from, string $to): string
    {
        return \sprintf(<<<'SQL'
            DO $$
            DECLARE
                p record;
            BEGIN
                FOR p IN
                    SELECT schemaname, tablename, policyname, qual, with_check
                    FROM pg_policies
                    WHERE qual LIKE '%%''%1$s''%%' OR with_check LIKE '%%''%1$s''%%'
                LOOP
                    IF p.qual IS NOT NULL THEN
                        EXECUTE format('ALTER POLICY %%I ON %%I.%%I USING (%%s)', p.policyname, p.schemaname, p.tablename, replace(p.qual, '''%1$s''', '''%2$s'''));
                    END IF;
                    IF p.with_check IS NOT NULL THEN
                        EXECUTE format('ALTER POLICY %%I ON %%I.%%I WITH CHECK (%%s)', p.policyname, p.schemaname, p.tablename, replace(p.with_check, '''%1$s''', '''%2$s'''));
                    END IF;
                END LOOP;
            END
            $$
            SQL, $from, $to);
    }
}
