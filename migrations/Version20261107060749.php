<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107060749 extends AbstractMigration
{
    /** The tables of the metadata: a change to any of them is a new revision. */
    private const array TABLES = ['metadata_object', 'metadata_field', 'metadata_form', 'metadata_view', 'metadata_translation'];

    public function getDescription(): string
    {
        return 'The revision of the metadata, bumped by triggers, and the id of the instance: the keys of the metadata cache';
    }

    public function up(Schema $schema): void
    {
        // One row: the instance's id, set once, and a revision every write to the metadata
        // tables bumps in its own transaction, whatever writes it (admin, console, templates).
        // Kept out of the ORM mapping (doctrine.dbal.schema_filter): the triggers own it.
        $this->addSql('CREATE TABLE core.metadata_state (
            singleton BOOLEAN DEFAULT TRUE NOT NULL,
            instance UUID DEFAULT gen_random_uuid() NOT NULL,
            revision BIGINT DEFAULT 0 NOT NULL,
            PRIMARY KEY (singleton),
            CONSTRAINT metadata_state_singleton CHECK (singleton)
        )');
        $this->addSql('INSERT INTO core.metadata_state DEFAULT VALUES');
        $this->addSql('CREATE FUNCTION core.bump_metadata_revision() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                UPDATE core.metadata_state SET revision = revision + 1;
                RETURN NULL;
            END
        $$');
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf(
                'CREATE TRIGGER bump_metadata_revision AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON core.%s FOR EACH STATEMENT EXECUTE FUNCTION core.bump_metadata_revision()',
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('DROP TRIGGER bump_metadata_revision ON core.%s', $table));
        }
        $this->addSql('DROP FUNCTION core.bump_metadata_revision()');
        $this->addSql('DROP TABLE core.metadata_state');
    }
}
