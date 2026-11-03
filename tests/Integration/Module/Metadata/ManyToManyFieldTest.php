<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Metadata;

use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A many-to-many field has no column: its links live in a table of their own,
 * data.l_<object>__<field>, which follows the field through its whole life (create, rename,
 * archive, purge). Runs real DDL, rolled back with each test.
 */
final class ManyToManyFieldTest extends KernelTestCase
{
    private MetadataManagerInterface $manager;
    private MetadataRegistryInterface $metadata;

    protected function setUp(): void
    {
        $this->manager = self::getContainer()->get(MetadataManagerInterface::class);
        $this->metadata = self::getContainer()->get(MetadataRegistryInterface::class);

        $this->manager->createObject('contact', 'Contact', 'Contacts');
        $this->manager->createField('contact', 'full_name', 'text', 'Full name', required: true);
        $this->manager->createObject('tag', 'Tag', 'Tags');
        $this->manager->createField('tag', 'name', 'text', 'Name', required: true);
    }

    public function testCreatingTheFieldCreatesItsLinkTable(): void
    {
        $this->manager->createField('contact', 'tags', 'many_to_many', 'Tags', settings: ['target' => 'tag', 'inverseName' => 'contacts', 'inverseLabel' => 'Contacts']);

        $field = $this->metadata->getObject('contact')->field('tags');
        self::assertFalse($field->storesColumn());
        self::assertSame('tag', $field->target());
        self::assertSame(['contacts'], array_map(
            static fn ($reference): ?string => $reference->settings->inverseName,
            $this->metadata->incomingManyToMany('tag'),
        ));
        self::assertSame([], $this->metadata->incomingRelations('tag'));

        self::assertArrayNotHasKey('tags', $this->columns('c_contact'));
        self::assertSame([
            'record_id' => 'uuid NOT NULL',
            'target_id' => 'uuid NOT NULL',
            'created_at' => 'timestamp(0) without time zone NOT NULL',
            'created_by' => 'uuid NULL',
        ], $this->columns('l_contact__tags'));
        self::assertEquals([
            'l_contact__tags_pkey' => 'PRIMARY KEY (record_id, target_id)',
            'fk_l_contact__tags_record_id' => 'FOREIGN KEY (record_id) REFERENCES data.c_contact(id) ON DELETE CASCADE',
            'fk_l_contact__tags_target_id' => 'FOREIGN KEY (target_id) REFERENCES data.c_tag(id) ON DELETE CASCADE',
        ], $this->constraints('l_contact__tags'));
        self::assertEqualsCanonicalizing([
            'CREATE UNIQUE INDEX l_contact__tags_pkey ON data.l_contact__tags USING btree (record_id, target_id)',
            'CREATE INDEX idx_l_contact__tags_target_id ON data.l_contact__tags USING btree (target_id)',
            'CREATE INDEX idx_l_contact__tags_record_id ON data.l_contact__tags USING btree (record_id)',
        ], $this->indexes('l_contact__tags'));

        // Synchronizing again changes nothing: the link table matches its definition.
        $synchronizer = self::getContainer()->get(SchemaSynchronizerInterface::class);
        self::assertTrue($synchronizer->plan('contact', allowDestructive: true)->isEmpty());
        self::assertTrue($synchronizer->plan('tag', allowDestructive: true)->isEmpty());
    }

    public function testARestrictRuleAndASelfLinkShapeTheForeignKeys(): void
    {
        $this->manager->createField('tag', 'related', 'many_to_many', 'Related tags', settings: ['target' => 'tag', 'onDelete' => 'restrict']);

        self::assertSame(
            'FOREIGN KEY (target_id) REFERENCES data.c_tag(id) ON DELETE RESTRICT',
            $this->constraints('l_tag__related')['fk_l_tag__related_target_id'],
        );
    }

    public function testRefusesWhatALinkFieldCannotBe(): void
    {
        $this->assertRefused(fn () => $this->createTags(['target' => 'nowhere']), 'The related object "nowhere" does not exist.');
        $this->assertRefused(fn () => $this->createTags(['target' => 'tag', 'onDelete' => 'cascade']), 'Setting "onDelete" must be one of: unlink, restrict.');
        $this->assertRefused(
            fn () => $this->manager->createField('contact', 'tags', 'many_to_many', 'Tags', unique: true, settings: ['target' => 'tag']),
            'Field "tags" links several records (many-to-many): it cannot be unique.',
        );

        $this->createTags(['target' => 'tag', 'inverseName' => 'contacts']);
        // Inverse names are shared by relations and many-to-many fields pointing at an object.
        $this->assertRefused(
            fn () => $this->manager->createField('contact', 'main_tag', 'relation', 'Main tag', settings: ['target' => 'tag', 'inverseName' => 'contacts']),
            'Object "tag" already has a field or an inverse relation named "contacts".',
        );
        $this->assertRefused(
            fn () => $this->manager->createField('contact', 'old_tags', 'many_to_many', 'Old tags', settings: ['target' => 'tag', 'inverseName' => 'contacts']),
            'already has a field or an inverse relation named "contacts"',
        );

        $this->assertRefused(fn () => $this->manager->configureLabel('contact', 'tags'), 'it cannot be the label of the records');
        $this->assertRefused(fn () => $this->manager->configureSearch('contact', null, ['full_name', 'tags']), 'it cannot be searched');
        $this->manager->createField('contact', 'notes', 'text', 'Notes');
        $this->assertRefused(fn () => $this->manager->configureFieldCondition('contact', 'notes', 'tags', ['x']), '"tags" cannot decide');
        $this->assertRefused(
            fn () => $this->manager->saveView('contact', ViewKind::Grid, 'main', "columns:\n  - full_name\n  - tags\n"),
            '"tags" is a many-to-many field: grid views cannot show it yet.',
        );
        $this->assertRefused(
            fn () => $this->manager->saveForm('signup', "label: Sign up\nobject: contact\nservice_fields:\n  - { name: interests, label: Interests, type: many_to_many, settings: { target: tag } }\n"),
            '"type" must be a field type other than relation and many_to_many',
        );
    }

    public function testTheLinkTableFollowsRenames(): void
    {
        $this->createTags(['target' => 'tag']);

        $this->manager->renameField('contact', 'tags', 'labels');
        self::assertFalse($this->tableExists('l_contact__tags'));
        self::assertSame(
            ['fk_l_contact__labels_record_id', 'fk_l_contact__labels_target_id', 'l_contact__labels_pkey'],
            $this->constraintNames('l_contact__labels'),
        );

        $this->manager->renameObject('contact', 'person');
        self::assertSame(
            ['fk_l_person__labels_record_id', 'fk_l_person__labels_target_id', 'l_person__labels_pkey'],
            $this->constraintNames('l_person__labels'),
        );
        self::assertEqualsCanonicalizing(
            ['l_person__labels_pkey', 'idx_l_person__labels_target_id', 'idx_l_person__labels_record_id'],
            $this->indexNames('l_person__labels'),
        );

        // Renaming the target only retargets the field: the link table is not named after it.
        $this->manager->renameObject('tag', 'topic');
        self::assertSame('topic', $this->metadata->getObject('person')->field('labels')->target());
        self::assertSame(
            'FOREIGN KEY (target_id) REFERENCES data.c_topic(id) ON DELETE CASCADE',
            $this->constraints('l_person__labels')['fk_l_person__labels_target_id'],
        );
        $synchronizer = self::getContainer()->get(SchemaSynchronizerInterface::class);
        $plan = $synchronizer->plan('person', allowDestructive: true);
        self::assertTrue($plan->isEmpty(), implode("\n", $plan->statements));
    }

    public function testArchivingKeepsTheLinkTableAndPurgingDropsIt(): void
    {
        $this->createTags(['target' => 'tag']);

        // The target is in use while the field points at it.
        $this->assertRefused(fn () => $this->manager->archiveObject('tag'), 'many-to-many field "contact.tags" points at it');

        $this->manager->archiveField('contact', 'tags');
        self::assertArrayHasKey('tags', $this->metadata->getObject('contact')->archivedFields);
        self::assertTrue($this->tableExists('l_contact__tags'));
        self::assertTrue(self::getContainer()->get(SchemaSynchronizerInterface::class)->plan('contact', allowDestructive: true)->isEmpty());

        // An archived field still holds the target's table through its foreign key.
        $this->manager->archiveObject('tag');
        $this->assertRefused(fn () => $this->manager->purgeObject('tag'), 'many-to-many field "contact.tags" (archived) points at it');
        $this->assertRefused(fn () => $this->manager->restoreField('contact', 'tags'), 'it points at object "tag", which is archived');
        $this->manager->restoreObject('tag');

        $this->manager->purgeField('contact', 'tags');
        self::assertFalse($this->tableExists('l_contact__tags'));
        $this->manager->createField('contact', 'tags', 'many_to_many', 'Tags', settings: ['target' => 'tag']);
        self::assertTrue($this->tableExists('l_contact__tags'));

        // Purging the object drops its link tables with its table.
        $this->manager->archiveObject('contact');
        $this->manager->purgeObject('contact');
        self::assertFalse($this->tableExists('l_contact__tags'));
        self::assertFalse($this->tableExists('c_contact'));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function createTags(array $settings): void
    {
        $this->manager->createField('contact', 'tags', 'many_to_many', 'Tags', settings: $settings);
    }

    private function assertRefused(callable $change, string $message): void
    {
        try {
            $change();
            self::fail('The change must be refused.');
        } catch (MetadataException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection()->fetchOne('SELECT to_regclass(?) IS NOT NULL', ['data.'.$table]);
    }

    /**
     * @return array<string, string> column name => "type NULL|NOT NULL", in table order
     */
    private function columns(string $table): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            "SELECT a.attname AS name, format_type(a.atttypid, a.atttypmod) AS type, a.attnotnull AS not_null
               FROM pg_attribute a
              WHERE a.attrelid = ('data.' || quote_ident(:table))::regclass AND a.attnum > 0 AND NOT a.attisdropped
              ORDER BY a.attnum",
            ['table' => $table],
        );

        $columns = [];
        foreach ($rows as $row) {
            self::assertIsString($row['name']);
            self::assertIsString($row['type']);
            $columns[$row['name']] = \sprintf('%s %s', $row['type'], true === $row['not_null'] ? 'NOT NULL' : 'NULL');
        }

        return $columns;
    }

    /**
     * @return array<string, string> constraint name => definition
     */
    private function constraints(string $table): array
    {
        /** @var array<string, string> $constraints */
        $constraints = $this->connection()->fetchAllKeyValue(
            "SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = ('data.' || quote_ident(:table))::regclass",
            ['table' => $table],
        );

        return $constraints;
    }

    /**
     * @return list<string> sorted
     */
    private function constraintNames(string $table): array
    {
        $names = array_keys($this->constraints($table));
        sort($names);

        return $names;
    }

    /**
     * @return list<string> index definitions
     */
    private function indexes(string $table): array
    {
        /** @var list<string> $indexes */
        $indexes = $this->connection()->fetchFirstColumn(
            'SELECT indexdef FROM pg_indexes WHERE schemaname = :schema AND tablename = :table',
            ['schema' => 'data', 'table' => $table],
        );

        return $indexes;
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        /** @var list<string> $names */
        $names = $this->connection()->fetchFirstColumn(
            'SELECT indexname FROM pg_indexes WHERE schemaname = :schema AND tablename = :table',
            ['schema' => 'data', 'table' => $table],
        );

        return $names;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
