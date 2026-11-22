<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Schema;

use App\Module\Metadata\Contract\Dto\KeyType;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Schema\Contract\DatabaseTablesInterface;
use App\Module\Schema\Contract\Dto\AdoptionRequest;
use App\Module\Schema\Contract\Dto\ColumnMapping;
use App\Module\Schema\Contract\Exception\AdoptionRefused;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use App\Module\Schema\Contract\TableAdopterInterface;
use App\Shared\Message\Message;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A table loaded by hand becoming an object: the metadata built from it, the table brought to
 * an object's shape with its records and keys, and nothing left for the synchronization to do.
 */
final class TableAdoptionTest extends KernelTestCase
{
    public function testProposesAFieldForEachColumn(): void
    {
        $this->sql('CREATE TABLE data."ProjectTask" (
            task_id serial PRIMARY KEY,
            "Title" varchar(80) NOT NULL,
            code varchar(20) UNIQUE,
            notes text,
            contact_email varchar(200),
            budget numeric(12,2),
            ratio double precision,
            hours smallint,
            done boolean,
            due date,
            started_at timestamptz,
            raw jsonb,
            created_at timestamp,
            sort text
        )');

        $proposal = $this->adopter()->propose('ProjectTask');

        self::assertSame(['project_task', 'Project task', KeyType::Integer], [$proposal->objectName, $proposal->labelSingular, $proposal->keyType]);
        $proposed = array_map(static fn ($column): ?array => null === $column->mapping() ? null : [$column->mapping()->fieldName, $column->mapping()->type, $column->mapping()->settings, $column->mapping()->required, $column->mapping()->unique], $proposal->columns);
        self::assertSame([
            'Title' => ['title', 'text', ['maxLength' => 80], true, false],
            'code' => ['code', 'text', ['maxLength' => 20], false, true],
            'notes' => ['notes', 'text', ['maxLength' => 10000, 'multiline' => true], false, false],
            'contact_email' => ['contact_email', 'email', [], false, false],
            'budget' => ['budget', 'number', ['decimals' => 2], false, false],
            'ratio' => ['ratio', 'number', ['decimals' => 2], false, false],
            'hours' => ['hours', 'number', ['decimals' => 0], false, false],
            'done' => ['done', 'boolean', [], false, false],
            'due' => ['due', 'date', [], false, false],
            'started_at' => ['started_at', 'date', ['includeTime' => true], false, false],
            'raw' => null,
            // Not of a system column's type: a field, under another name.
            'sort' => ['sort_legacy', 'text', ['maxLength' => 10000, 'multiline' => true], false, false],
        ], $proposed);
        // A system column's name and type: reused as one, not a field.
        self::assertArrayNotHasKey('created_at', $proposal->columns);
        self::assertSame(['email', 'text', 'url', 'tel'], array_keys($proposal->columns['contact_email']->options));
    }

    public function testAdoptsATableWithItsRecordsAndKeys(): void
    {
        $this->sql('CREATE TABLE data.project (
            pid serial PRIMARY KEY,
            name varchar(80) NOT NULL UNIQUE,
            budget numeric(12,2) DEFAULT 0,
            starts date,
            created_at timestamp,
            raw jsonb
        )');
        $this->sql("INSERT INTO data.project (name, budget, starts, created_at) VALUES ('Alpha', 10.5, '2026-01-01', '2026-01-02 03:04:05'), ('Beta', NULL, NULL, NULL)");
        $this->sql('DELETE FROM data.project WHERE name = \'Beta\'');
        $this->sql("INSERT INTO data.project (name) VALUES ('Gamma')");

        $report = $this->adopter()->adopt($this->adopter()->propose('project')->request());

        self::assertSame('project', $report->objectName);
        self::assertFalse($report->dryRun);
        $object = $this->registry()->getObject('project');
        self::assertSame([KeyType::Integer, 'c_project'], [$object->keyType, $object->tableName]);
        self::assertSame(['name', 'budget', 'starts'], array_keys($object->fields));
        self::assertTrue($object->field('name')->isRequired);
        self::assertTrue($object->field('name')->isUnique);
        self::assertNull($this->tables()->find('project'));

        // The records kept their keys; a new one comes after the largest.
        $finder = self::getContainer()->get(RecordFinderInterface::class);
        $alpha = $finder->findBy('project', 'name', 'Alpha');
        self::assertSame('1', $alpha?->id);
        self::assertEquals('10.50', $alpha->get('budget'));
        self::assertSame('2026-01-02 03:04:05', $this->connection()->fetchOne('SELECT created_at FROM data.c_project WHERE id = 1'));
        self::assertSame('3', $finder->findBy('project', 'name', 'Gamma')?->id);
        $delta = $this->records()->create(new RecordInput('project', ['name' => 'Delta']));
        self::assertSame('4', $delta->id);

        // The field's rules hold: required, unique.
        try {
            $this->records()->create(new RecordInput('project', ['name' => 'Alpha']));
            self::fail('A name taken twice.');
        } catch (RecordValidationFailed $e) {
            self::assertArrayHasKey('name', $e->errors);
        }
        // The column left out is still there, unknown to the application.
        self::assertEquals(1, $this->connection()->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'data' AND table_name = 'c_project' AND column_name = 'raw'"));

        // Nothing left for the synchronization but the column left out.
        $plan = self::getContainer()->get(SchemaSynchronizerInterface::class)->plan('project');
        self::assertSame([], $plan->statements);
        self::assertSame(['Column "raw" is not defined in the metadata: not dropped.'], $plan->skipped);
    }

    public function testAdoptsAUuidKeyAndARelationToAnObject(): void
    {
        $manager = self::getContainer()->get(MetadataManagerInterface::class);
        $manager->createObject('team', 'Team', 'Teams', keyType: KeyType::Integer);
        $manager->createField('team', 'name', 'text', 'Name');
        $red = $this->records()->create(new RecordInput('team', ['name' => 'Red']));
        $this->sql('CREATE TABLE data.member (
            uid uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            "fullName" text NOT NULL,
            team integer REFERENCES data.c_team (id) ON DELETE CASCADE,
            first varchar(40),
            last varchar(40),
            UNIQUE (first, last)
        )');
        $this->sql(\sprintf("INSERT INTO data.member (\"fullName\", team, first, last) VALUES ('Ada', %d, 'Ada', 'L')", (int) $red->id));

        $proposal = $this->adopter()->propose('member');
        self::assertSame(['relation', 'number'], array_keys($proposal->columns['team']->options));
        $this->adopter()->adopt($proposal->request());

        $object = $this->registry()->getObject('member');
        self::assertSame(KeyType::Uuid, $object->keyType);
        self::assertSame(['target' => 'team', 'onDelete' => 'cascade'], array_intersect_key($object->field('team')->settings, ['target' => 0, 'onDelete' => 0]));
        self::assertSame([['first', 'last']], $object->uniqueTogether);
        $ada = self::getContainer()->get(RecordFinderInterface::class)->findBy('member', 'full_name', 'Ada');
        self::assertSame($red->id, $ada?->get('team'));
        // A new record gets its UUID from the application.
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->records()->create(new RecordInput('member', ['full_name' => 'Bea']))->id);
        // The delete rule of the foreign key is the field's now.
        $this->records()->delete('team', $red->id);
        self::assertNull(self::getContainer()->get(RecordFinderInterface::class)->find('member', $ada->id));

        self::assertSame([], self::getContainer()->get(SchemaSynchronizerInterface::class)->plan('member')->statements);
    }

    public function testADryRunChangesNothing(): void
    {
        $this->sql('CREATE TABLE data.project (id serial PRIMARY KEY, name text)');

        $report = $this->adopter()->adopt($this->adopter()->propose('project')->request(), dryRun: true);

        self::assertTrue($report->dryRun);
        self::assertContains('ALTER TABLE "data"."project" RENAME TO "c_project"', $report->statements);
        self::assertFalse($this->registry()->hasObject('project'));
        self::assertNotNull($this->tables()->find('project'));
    }

    public function testRefusesWhatItCannotDo(): void
    {
        $this->sql('CREATE TABLE data.loose (name text)');
        $this->sql('CREATE TABLE data.project (id serial PRIMARY KEY, name text NOT NULL, big bigint, raw jsonb NOT NULL)');
        $this->sql('INSERT INTO data.project (name, big, raw) VALUES (\'A\', 9999999999, \'{}\')');
        self::getContainer()->get(MetadataManagerInterface::class)->createObject('taken', 'Taken', 'Takens');

        self::assertSame(['It has no primary key: give it one first.'], $this->reasons(fn () => $this->adopter()->propose('loose')));
        $request = $this->adopter()->propose('project')->request();
        self::assertSame(['Column raw cannot be left out: it may not be empty and has no default, so no record could be written.'], $this->reasons(fn () => $this->adopter()->adopt($request)));

        $columns = ['raw' => new ColumnMapping('raw', 'Raw', 'text', ['maxLength' => 10000, 'multiline' => true])] + $request->columns;
        $named = static function (string $name) use (&$columns): AdoptionRequest {
            return new AdoptionRequest('project', $name, 'Project', 'Projects', $columns);
        };
        // A value the field's column cannot hold: the database says which, nothing is changed.
        self::assertSame(['The database refused it: integer out of range'], $this->reasons(fn () => $this->adopter()->adopt($named('project'))));
        unset($columns['big']);
        self::assertSame(['Column big: say which field it becomes, or leave it out.'], $this->reasons(fn () => $this->adopter()->adopt($named('project'))));
        $columns['big'] = null;
        self::assertSame(['An object is named "taken" already: choose another name.'], $this->reasons(fn () => $this->adopter()->adopt($named('taken'))));
        self::assertSame(['The object\'s name "Project" is not a machine name: lowercase letters, digits and single underscores, starting with a letter.'], $this->reasons(fn () => $this->adopter()->adopt($named('Project'))));
        // A field name the metadata refuses: told by the metadata, nothing changed.
        $columns['name'] = new ColumnMapping('Name!', 'Name', 'text', ['maxLength' => 10000, 'multiline' => true]);
        self::assertStringStartsWith('The metadata refused it:', $this->reasons(fn () => $this->adopter()->adopt($named('project')))[0]);
        $columns['name'] = $request->columns['name'];
        self::assertNotNull($this->tables()->find('project'));

        $this->adopter()->adopt($named('project'));
        self::assertEquals(9999999999, $this->connection()->fetchOne('SELECT big FROM data.c_project'));
    }

    /**
     * @return list<string>
     */
    private function reasons(\Closure $call): array
    {
        try {
            $call();
        } catch (AdoptionRefused $e) {
            return array_map(static fn (Message $reason): string => (string) $reason, $e->reasons);
        }
        self::fail('Not refused.');
    }

    private function adopter(): TableAdopterInterface
    {
        return self::getContainer()->get(TableAdopterInterface::class);
    }

    private function tables(): DatabaseTablesInterface
    {
        return self::getContainer()->get(DatabaseTablesInterface::class);
    }

    private function registry(): MetadataRegistryInterface
    {
        return self::getContainer()->get(MetadataRegistryInterface::class);
    }

    private function records(): RecordManagerInterface
    {
        return self::getContainer()->get(RecordManagerInterface::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function sql(string $sql): void
    {
        $this->connection()->executeStatement($sql);
    }
}
