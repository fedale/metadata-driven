<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Schema;

use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use App\Module\Schema\Domain\Repository\SchemaChangeLogRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs real DDL against the PostgreSQL test database; each test is rolled back,
 * DDL included, because PostgreSQL DDL is transactional.
 */
final class SchemaSynchronizationTest extends KernelTestCase
{
    private CreateObjectHandler $createObject;
    private CreateFieldHandler $createField;
    private SchemaSynchronizerInterface $synchronizer;
    private Connection $connection;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->createObject = $container->get(CreateObjectHandler::class);
        $this->createField = $container->get(CreateFieldHandler::class);
        $this->synchronizer = $container->get(SchemaSynchronizerInterface::class);
        $this->connection = $container->get(Connection::class);
    }

    public function testCreatingAnObjectCreatesItsTable(): void
    {
        ($this->createObject)(new CreateObjectCommand('pet', 'Pet', 'Pets'));

        self::assertSame([
            'id' => 'uuid NOT NULL',
            'created_at' => 'timestamp(0) without time zone NOT NULL',
            'updated_at' => 'timestamp(0) without time zone NOT NULL',
            'created_by' => 'uuid NULL',
            'deleted_at' => 'timestamp(0) without time zone NULL',
            'sort' => 'integer NOT NULL',
        ], $this->columns('c_pet'));
        self::assertSame(['c_pet_pkey' => 'PRIMARY KEY (id)'], $this->constraints('c_pet'));
    }

    public function testAddingFieldsAddsColumnsAndUniqueIndexes(): void
    {
        ($this->createObject)(new CreateObjectCommand('pet', 'Pet', 'Pets'));
        ($this->createField)(new CreateFieldCommand('pet', 'name', 'text', 'Name', isRequired: true, settings: ['maxLength' => 80]));
        ($this->createField)(new CreateFieldCommand('pet', 'notes', 'text', 'Notes', settings: ['multiline' => true]));
        ($this->createField)(new CreateFieldCommand('pet', 'weight', 'number', 'Weight', settings: ['decimals' => 2]));
        ($this->createField)(new CreateFieldCommand('pet', 'age', 'number', 'Age'));
        ($this->createField)(new CreateFieldCommand('pet', 'vaccinated', 'boolean', 'Vaccinated'));
        ($this->createField)(new CreateFieldCommand('pet', 'born_on', 'date', 'Born on'));
        ($this->createField)(new CreateFieldCommand('pet', 'chip_code', 'text', 'Chip code', isUnique: true));
        ($this->createField)(new CreateFieldCommand('pet', 'owner_email', 'email', 'Owner email'));

        $columns = $this->columns('c_pet');
        // "required" is enforced by validation: columns stay nullable so they can be added to filled tables.
        self::assertSame('character varying(80) NULL', $columns['name']);
        self::assertSame('text NULL', $columns['notes']);
        self::assertSame('numeric(18,2) NULL', $columns['weight']);
        self::assertSame('integer NULL', $columns['age']);
        self::assertSame('boolean NULL', $columns['vaccinated']);
        self::assertSame('date NULL', $columns['born_on']);
        self::assertSame('character varying(254) NULL', $columns['owner_email']);

        self::assertContains('CREATE UNIQUE INDEX uniq_c_pet_chip_code ON data.c_pet USING btree (chip_code)', $this->indexes('c_pet'));
    }

    public function testSynchronizationIsIdempotent(): void
    {
        ($this->createObject)(new CreateObjectCommand('pet', 'Pet', 'Pets'));
        ($this->createField)(new CreateFieldCommand('pet', 'chip_code', 'text', 'Chip code', isUnique: true));
        ($this->createField)(new CreateFieldCommand('pet', 'weight', 'number', 'Weight', settings: ['decimals' => 2]));

        $plan = $this->synchronizer->plan('pet', allowDestructive: true);

        self::assertTrue($plan->isEmpty(), implode("\n", $plan->statements));
        self::assertSame([], $plan->skipped);
    }

    public function testSortIsAddedToTablesThatHoldRecords(): void
    {
        ($this->createObject)(new CreateObjectCommand('pet', 'Pet', 'Pets'));
        // A table created before the sort column existed, already holding a record.
        $this->connection->executeStatement('ALTER TABLE data.c_pet DROP sort');
        $this->connection->executeStatement(
            "INSERT INTO data.c_pet (id, created_at, updated_at) VALUES ('01a14b6b-f371-729f-8e48-9a43d9f04c84', NOW(), NOW())",
        );

        $plan = $this->synchronizer->synchronize('pet');

        self::assertSame(['ALTER TABLE data.c_pet ADD sort INT DEFAULT 0 NOT NULL'], $plan->statements);
        self::assertSame(0, $this->connection->fetchOne('SELECT sort FROM data.c_pet'));
        $this->connection->executeStatement('UPDATE data.c_pet SET sort = -5');
        self::assertSame(-5, $this->connection->fetchOne('SELECT sort FROM data.c_pet'));
        self::assertTrue($this->synchronizer->plan('pet')->isEmpty());
    }

    public function testReservedSqlKeywordsAreQuoted(): void
    {
        ($this->createObject)(new CreateObjectCommand('user', 'User', 'Users'));
        ($this->createField)(new CreateFieldCommand('user', 'order', 'number', 'Order'));
        ($this->createField)(new CreateFieldCommand('user', 'select', 'text', 'Select', isUnique: true));

        self::assertArrayHasKey('order', $this->columns('c_user'));
        self::assertArrayHasKey('select', $this->columns('c_user'));
    }

    public function testAppliedChangesAreLogged(): void
    {
        ($this->createObject)(new CreateObjectCommand('pet', 'Pet', 'Pets'));
        ($this->createField)(new CreateFieldCommand('pet', 'name', 'text', 'Name'));

        $logs = self::getContainer()->get(SchemaChangeLogRepository::class)->findByObjectName('pet');

        self::assertCount(2, $logs);
        self::assertStringStartsWith('ALTER TABLE data.c_pet ADD name', $logs[0]->getStatements()[0]);
        self::assertStringStartsWith('CREATE TABLE data.c_pet', $logs[1]->getStatements()[0]);
    }

    public function testDestructiveChangesAreSkippedUnlessAllowed(): void
    {
        ($this->createObject)(new CreateObjectCommand('pet', 'Pet', 'Pets'));
        $this->connection->executeStatement('ALTER TABLE data.c_pet ADD legacy_code VARCHAR(20)');

        $plan = $this->synchronizer->synchronize('pet');
        self::assertTrue($plan->isEmpty());
        self::assertSame(['Column "legacy_code" is not defined in the metadata: not dropped.'], $plan->skipped);
        self::assertArrayHasKey('legacy_code', $this->columns('c_pet'));

        $plan = $this->synchronizer->synchronize('pet', allowDestructive: true);
        self::assertSame(['ALTER TABLE data.c_pet DROP "legacy_code"'], $plan->statements);
        self::assertArrayNotHasKey('legacy_code', $this->columns('c_pet'));
    }

    public function testFailingDdlRollsBackTheDefinition(): void
    {
        // A pre-existing table whose duplicate ids make the primary key impossible to add.
        $this->connection->executeStatement('CREATE TABLE data.c_toy (id UUID, created_at TIMESTAMP(0), updated_at TIMESTAMP(0))');
        $this->connection->executeStatement(
            "INSERT INTO data.c_toy (id) VALUES ('01a14b6b-f371-729f-8e48-9a43d9f04c84'), ('01a14b6b-f371-729f-8e48-9a43d9f04c84')",
        );

        try {
            ($this->createObject)(new CreateObjectCommand('toy', 'Toy', 'Toys'));
            self::fail('The DDL was expected to fail.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('c_toy', $e->getMessage());
        }

        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM core.metadata_object WHERE name = 'toy'"));
        self::assertArrayNotHasKey('created_by', $this->columns('c_toy'));
    }

    /**
     * @return array<string, string> column name => "type NULL|NOT NULL", in table order
     */
    private function columns(string $table): array
    {
        $rows = $this->connection->fetchAllAssociative(
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
        $constraints = $this->connection->fetchAllKeyValue(
            "SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = ('data.' || quote_ident(:table))::regclass",
            ['table' => $table],
        );

        return $constraints;
    }

    /**
     * @return list<string> index definitions
     */
    private function indexes(string $table): array
    {
        /** @var list<string> $indexes */
        $indexes = $this->connection->fetchFirstColumn(
            'SELECT indexdef FROM pg_indexes WHERE schemaname = :schema AND tablename = :table',
            ['schema' => 'data', 'table' => $table],
        );

        return $indexes;
    }
}
