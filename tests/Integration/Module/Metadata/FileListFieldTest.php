<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Metadata;

use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Application\Grid\RecordGridSourceInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use Doctrine\DBAL\Connection;
use Fedale\GridviewBundle\DataSource\GridQuery;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A file field with "multiple": true has no column: its files are rows of a link table,
 * data.l_<object>__<field>, which follows the field through its whole life (create, rename,
 * archive, purge). Runs real DDL, rolled back with each test.
 */
final class FileListFieldTest extends KernelTestCase
{
    private MetadataManagerInterface $manager;
    private MetadataRegistryInterface $metadata;

    protected function setUp(): void
    {
        $this->manager = self::getContainer()->get(MetadataManagerInterface::class);
        $this->metadata = self::getContainer()->get(MetadataRegistryInterface::class);

        $this->manager->createObject('product', 'Product', 'Products');
        $this->manager->createField('product', 'name', 'text', 'Name', required: true);
    }

    public function testCreatingTheFieldCreatesItsLinkTable(): void
    {
        $this->createGallery(['multiple' => true, 'max' => 12, 'image' => true]);

        $field = $this->metadata->getObject('product')->field('gallery');
        self::assertFalse($field->storesColumn());
        self::assertSame(12, $field->fileList()?->max);
        self::assertNull($field->manyToMany());
        self::assertNull($field->target());

        self::assertArrayNotHasKey('gallery', $this->columns('c_product'));
        self::assertSame([
            'record_id' => 'uuid NOT NULL',
            'target_id' => 'uuid NOT NULL',
            'position' => 'integer NOT NULL',
        ], $this->columns('l_product__gallery'));
        self::assertEquals([
            'l_product__gallery_pkey' => 'PRIMARY KEY (record_id, target_id)',
            'fk_l_product__gallery_record_id' => 'FOREIGN KEY (record_id) REFERENCES data.c_product(id) ON DELETE CASCADE',
            // core.file: PostgreSQL leaves out a schema of the search path.
            'fk_l_product__gallery_target_id' => 'FOREIGN KEY (target_id) REFERENCES file(id) ON DELETE RESTRICT',
        ], $this->constraints('l_product__gallery'));
        self::assertEqualsCanonicalizing([
            'CREATE UNIQUE INDEX l_product__gallery_pkey ON data.l_product__gallery USING btree (record_id, target_id)',
            'CREATE INDEX idx_l_product__gallery_target_id ON data.l_product__gallery USING btree (target_id)',
            'CREATE INDEX idx_l_product__gallery_record_id ON data.l_product__gallery USING btree (record_id)',
        ], $this->indexes('l_product__gallery'));

        // Synchronizing again changes nothing: the link table matches its definition.
        self::assertTrue(self::getContainer()->get(SchemaSynchronizerInterface::class)->plan('product', allowDestructive: true)->isEmpty());
    }

    public function testAFieldHoldingOneFileKeepsItsColumn(): void
    {
        $this->createGallery([]);

        $field = $this->metadata->getObject('product')->field('gallery');
        self::assertTrue($field->storesColumn());
        self::assertNull($field->fileList());
        self::assertArrayHasKey('gallery', $this->columns('c_product'));
        self::assertFalse($this->tableExists('l_product__gallery'));
    }

    public function testRefusesWhatAFileFieldCannotBe(): void
    {
        $this->assertRefused(fn () => $this->createGallery(['max' => 3]), 'Setting "max" applies to a field holding several files ("multiple") only.');
        $this->assertRefused(fn () => $this->createGallery(['multiple' => true, 'max' => 0]), 'Setting "max" must be an integer between 1 and 100');

        $this->createGallery(['multiple' => true]);
        // One file or several decides where the files are: it cannot change.
        $this->assertRefused(fn () => $this->manager->configureFieldSettings('product', 'gallery', ['multiple' => false]), '"multiple"');
        // The limit can.
        $this->manager->configureFieldSettings('product', 'gallery', ['multiple' => true, 'max' => 4]);
        self::assertSame(4, $this->metadata->getObject('product')->field('gallery')->fileList()?->max);

        // A file, one or several, has no text of its own.
        $this->manager->createField('product', 'manual', 'file', 'Manual');
        foreach (['gallery', 'manual'] as $name) {
            $this->assertRefused(fn () => $this->manager->configureLabel('product', $name), \sprintf('Field "%s" is a file field, with no text of its own: it cannot be the label of the records.', $name));
            $this->assertRefused(fn () => $this->manager->configureSearch('product', null, ['name', $name]), \sprintf('Field "%s" is a file field, with no text of its own: it cannot be searched.', $name));
        }
    }

    public function testTheLinkTableFollowsRenames(): void
    {
        $this->createGallery(['multiple' => true]);

        $this->manager->renameField('product', 'gallery', 'photos');
        self::assertFalse($this->tableExists('l_product__gallery'));
        $this->manager->renameObject('product', 'item');
        self::assertSame(
            ['fk_l_item__photos_record_id', 'fk_l_item__photos_target_id', 'l_item__photos_pkey'],
            $this->constraintNames('l_item__photos'),
        );
        self::assertEqualsCanonicalizing(
            ['l_item__photos_pkey', 'idx_l_item__photos_target_id', 'idx_l_item__photos_record_id'],
            $this->indexNames('l_item__photos'),
        );
        $plan = self::getContainer()->get(SchemaSynchronizerInterface::class)->plan('item', allowDestructive: true);
        self::assertTrue($plan->isEmpty(), implode("\n", $plan->statements));
    }

    public function testArchivingKeepsTheLinkTableAndPurgingDropsIt(): void
    {
        $this->createGallery(['multiple' => true]);

        $this->manager->archiveField('product', 'gallery');
        self::assertTrue($this->tableExists('l_product__gallery'));
        self::assertTrue(self::getContainer()->get(SchemaSynchronizerInterface::class)->plan('product', allowDestructive: true)->isEmpty());
        $this->manager->restoreField('product', 'gallery');

        $this->manager->archiveField('product', 'gallery');
        $this->manager->purgeField('product', 'gallery');
        self::assertFalse($this->tableExists('l_product__gallery'));

        $this->createGallery(['multiple' => true]);
        $this->manager->archiveObject('product');
        $this->manager->purgeObject('product');
        self::assertFalse($this->tableExists('l_product__gallery'));
        self::assertFalse($this->tableExists('c_product'));
    }

    public function testARecordWithoutFilesReadsAnEmptyList(): void
    {
        $this->createGallery(['multiple' => true]);
        $record = self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('product', ['name' => 'Oslo chair']));

        self::assertSame([], $record->values['gallery']);
        // The grid shows no files until phase 7 of the plan.
        $rows = iterator_to_array(self::getContainer()->get(RecordGridSourceInterface::class)->fetch(new GridQuery(source: ['object' => 'product'])), false);
        self::assertSame('Oslo chair', $rows[0]['name']);
        self::assertSame([], $rows[0]['gallery'.RecordGridSourceInterface::FILE_SUFFIX]);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function createGallery(array $settings): void
    {
        $this->manager->createField('product', 'gallery', 'file', 'Gallery', settings: $settings);
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
