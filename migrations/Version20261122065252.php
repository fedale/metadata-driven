<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The type of an object's key ("uuid" or "integer"), fixed when the object is made; the objects
 * there already are UUID ones.
 *
 * A workflow run names the record it ran on, of any object: its id is kept as text, so that it
 * holds a key of any type.
 */
final class Version20261122065252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The key type of an object; a workflow run\'s record id as text';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE core.metadata_object ADD key_type VARCHAR(10) DEFAULT \'uuid\' NOT NULL');
        $this->addSql('ALTER TABLE core.workflow_run ALTER record_id TYPE TEXT');
    }

    public function down(Schema $schema): void
    {
        // Refused by PostgreSQL while a run names a record by an integer key.
        $this->addSql('ALTER TABLE core.workflow_run ALTER record_id TYPE UUID USING record_id::uuid');
        $this->addSql('ALTER TABLE core.metadata_object DROP key_type');
    }
}
