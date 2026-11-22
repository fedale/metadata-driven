<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Schema;

use App\Module\Metadata\Contract\Dto\KeyType;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Schema\Contract\Dto\AdoptionProposal;
use App\Module\Schema\Contract\Dto\AdoptionRequest;
use App\Module\Schema\Contract\Exception\AdoptionRefused;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use App\Module\Schema\Contract\TableAdopterInterface;
use App\Shared\Message\Message;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tables loaded together, pointing at one another with their own keys, adopted together: each
 * foreign key between them a relation, the keys and the links between the records kept.
 *
 *  - project (serial)
 *  - project_task (serial): project → project (cascade), parent → project_task
 *  - project_task_item (uuid): task → project_task, supplier → supplier (not adopted)
 *  - supplier (serial)
 */
final class TableSetAdoptionTest extends KernelTestCase
{
    protected function setUp(): void
    {
        $this->sql('CREATE TABLE data.supplier (id serial PRIMARY KEY, name text)');
        $this->sql('CREATE TABLE data.project (id serial PRIMARY KEY, name varchar(80) NOT NULL)');
        $this->sql('CREATE TABLE data.project_task (
            task_id serial PRIMARY KEY,
            project_id integer NOT NULL REFERENCES data.project (id) ON DELETE CASCADE,
            parent integer REFERENCES data.project_task (task_id),
            title text
        )');
        $this->sql('CREATE TABLE data.project_task_item (
            id uuid PRIMARY KEY,
            task integer REFERENCES data.project_task (task_id) ON DELETE SET NULL,
            supplier integer REFERENCES data.supplier (id),
            label text
        )');
        $this->sql("INSERT INTO data.supplier (name) VALUES ('Acme')");
        $this->sql("INSERT INTO data.project (name) VALUES ('Alpha'), ('Beta')");
        $this->sql('INSERT INTO data.project_task (project_id, parent, title) VALUES (2, NULL, \'Plan\'), (2, 1, \'Draft\')');
        $this->sql("INSERT INTO data.project_task_item (id, task, supplier, label) VALUES ('0190a5e2-4c8b-7d3e-9f10-2b3c4d5e6f70', 2, 1, 'Paper')");
    }

    public function testTellsTheTablesLinkedToSome(): void
    {
        self::assertSame(['pointedAt' => ['project'], 'pointing' => ['project_task_item']], $this->adopter()->related(['project_task']));
        self::assertSame(['pointedAt' => ['supplier', 'project_task'], 'pointing' => []], $this->adopter()->related(['project_task_item']));
    }

    public function testProposesRelationsBetweenTheTablesOfASet(): void
    {
        $proposals = $this->adopter()->proposeSet(['project_task_item', 'project_task', 'project']);

        // The tables pointed at first.
        self::assertSame(['project', 'project_task', 'project_task_item'], array_map(static fn (AdoptionProposal $proposal): string => $proposal->table->name, $proposals));
        $task = $proposals[1]->columns;
        self::assertSame(['relation', 'project', 'project', 'cascade'], [$task['project_id']->proposed, $task['project_id']->mapping()?->targetTable, $task['project_id']->mapping()->settings['target'] ?? null, $task['project_id']->mapping()->settings['onDelete'] ?? null]);
        self::assertSame(['relation', 'project_task'], [$task['parent']->proposed, $task['parent']->mapping()?->targetTable]);
        $item = $proposals[2]->columns;
        self::assertSame(['relation', 'project_task', 'set_null'], [$item['task']->proposed, $item['task']->mapping()?->targetTable, $item['task']->mapping()->settings['onDelete'] ?? null]);
        // Not adopted with them: a number, its foreign key left as it is.
        self::assertSame('number', $item['supplier']->proposed);

        // Alone, a table points at no object: its foreign keys are numbers.
        self::assertSame('number', $this->adopter()->propose('project_task')->columns['project_id']->proposed);
    }

    public function testAdoptsASetWithTheLinksBetweenTheirRecords(): void
    {
        $proposals = $this->adopter()->proposeSet(['project', 'project_task', 'project_task_item']);
        $reports = $this->adopter()->adoptSet(array_map(static fn (AdoptionProposal $proposal) => $proposal->request(), $proposals));
        self::assertSame(['project', 'project_task', 'project_task_item'], array_map(static fn ($report): string => $report->objectName, $reports));

        $registry = self::getContainer()->get(MetadataRegistryInterface::class);
        self::assertSame(KeyType::Integer, $registry->getObject('project_task')->keyType);
        self::assertSame(KeyType::Uuid, $registry->getObject('project_task_item')->keyType);
        self::assertSame('project', $registry->getObject('project_task')->field('project_id')->relation()?->target);
        self::assertSame('project_task', $registry->getObject('project_task')->field('parent')->relation()?->target);
        self::assertSame('project_task', $registry->getObject('project_task_item')->field('task')->relation()?->target);
        self::assertNull($registry->getObject('project_task_item')->field('supplier')->relation());

        $finder = self::getContainer()->get(RecordFinderInterface::class);
        $draft = $finder->find('project_task', '2');
        self::assertNotNull($draft);
        self::assertSame(['2', '1', 'Draft'], [$draft->get('project_id'), $draft->get('parent'), $draft->get('title')]);
        self::assertSame('2', $finder->find('project_task_item', '0190a5e2-4c8b-7d3e-9f10-2b3c4d5e6f70')?->get('task'));
        self::assertSame(['Plan', 'Draft'], array_map(static fn ($task): mixed => $task->get('title'), $finder->findReferencing('project_task', 'project_id', '2')));

        // The delete rules of the foreign keys are the fields' now.
        $records = self::getContainer()->get(RecordManagerInterface::class);
        $records->delete('project_task', '2');
        self::assertNull($finder->find('project_task_item', '0190a5e2-4c8b-7d3e-9f10-2b3c4d5e6f70')?->get('task'));
        // A new record numbered on, pointing at one adopted.
        self::assertSame('3', $records->create(new RecordInput('project_task', ['project_id' => '1', 'title' => 'New']))->id);

        $synchronizer = self::getContainer()->get(SchemaSynchronizerInterface::class);
        foreach (['project', 'project_task', 'project_task_item'] as $object) {
            self::assertSame([], $synchronizer->plan($object)->statements, $object);
        }
        // The table left out still points at its own: supplier is untouched.
        self::assertEquals(1, $this->connection()->fetchOne("SELECT COUNT(*) FROM pg_constraint WHERE conrelid = 'data.c_project_task_item'::regclass AND confrelid = 'data.supplier'::regclass"));
    }

    public function testAdoptsTablesPointingAtEachOtherInACircle(): void
    {
        $this->sql('CREATE TABLE data.husband (id serial PRIMARY KEY, name text)');
        $this->sql('CREATE TABLE data.wife (id serial PRIMARY KEY, name text, husband integer REFERENCES data.husband (id))');
        $this->sql('ALTER TABLE data.husband ADD COLUMN wife integer REFERENCES data.wife (id)');

        $reports = $this->adopter()->adoptSet(array_map(static fn (AdoptionProposal $proposal) => $proposal->request(), $this->adopter()->proposeSet(['husband', 'wife'])));

        self::assertCount(2, $reports);
        $registry = self::getContainer()->get(MetadataRegistryInterface::class);
        self::assertSame('wife', $registry->getObject('husband')->field('wife')->relation()?->target);
        self::assertSame('husband', $registry->getObject('wife')->field('husband')->relation()?->target);
    }

    public function testAllOrNothing(): void
    {
        $proposals = $this->adopter()->proposeSet(['project', 'project_task']);
        $requests = array_map(static fn (AdoptionProposal $proposal) => $proposal->request(), $proposals);

        // A relation to a table not adopted with it.
        self::assertSame(['Column project_id is a relation to table project, which is not adopted with it: adopt both together, or make it no relation.'], $this->reasons(fn () => $this->adopter()->adoptSet([$requests[1]])));
        // Two tables, one name.
        $twice = new AdoptionRequest('project_task', 'project', 'Task', 'Tasks', $requests[1]->columns);
        self::assertSame(['Two tables would become the object "project": give one another name.'], $this->reasons(fn () => $this->adopter()->adoptSet([$requests[0], $twice])));
        // A dry run of both: nothing kept.
        self::assertCount(2, $this->adopter()->adoptSet($requests, dryRun: true));
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('project'));
        self::assertNotNull($this->connection()->fetchOne("SELECT to_regclass('data.project')"));
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

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function sql(string $sql): void
    {
        $this->connection()->executeStatement($sql);
    }
}
