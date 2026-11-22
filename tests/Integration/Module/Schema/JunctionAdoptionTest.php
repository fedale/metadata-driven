<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Schema;

use App\Module\Metadata\Contract\Dto\KeyType;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Schema\Contract\DatabaseTablesInterface;
use App\Module\Schema\Contract\Dto\AdoptionProposal;
use App\Module\Schema\Contract\Dto\AdoptionRequest;
use App\Module\Schema\Contract\Dto\JunctionMapping;
use App\Module\Schema\Contract\Exception\AdoptionRefused;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use App\Module\Schema\Contract\TableAdopterInterface;
use App\Shared\Message\Message;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tables linking two others, their primary key made of two foreign keys: adopted as the links of
 * a many-to-many field, or, with columns of their own, as a junction object the field goes
 * through. The links between the records stay.
 *
 *  - task (serial), tag (uuid)
 *  - task_tag (task_id, tag_id): the links alone
 *  - task_member (task, person, role): links with a role
 *  - person (serial)
 */
final class JunctionAdoptionTest extends KernelTestCase
{
    private const string URGENT = '0190a5e2-4c8b-7d3e-9f10-2b3c4d5e6f70';
    private const string LATER = '0190a5e2-4c8b-7d3e-9f10-2b3c4d5e6f71';

    protected function setUp(): void
    {
        $this->sql('CREATE TABLE data.task (id serial PRIMARY KEY, title text)');
        $this->sql('CREATE TABLE data.tag (id uuid PRIMARY KEY, name text)');
        $this->sql('CREATE TABLE data.person (id serial PRIMARY KEY, name text)');
        $this->sql('CREATE TABLE data.task_tag (
            task_id integer REFERENCES data.task (id) ON DELETE CASCADE,
            tag_id uuid REFERENCES data.tag (id) ON DELETE CASCADE,
            PRIMARY KEY (task_id, tag_id)
        )');
        $this->sql('CREATE TABLE data.task_member (
            task integer REFERENCES data.task (id) ON DELETE CASCADE,
            person integer REFERENCES data.person (id) ON DELETE CASCADE,
            role varchar(20),
            PRIMARY KEY (task, person)
        )');
        $this->sql("INSERT INTO data.task (title) VALUES ('Write'), ('Read')");
        $this->sql(\sprintf("INSERT INTO data.tag (id, name) VALUES ('%s', 'Urgent'), ('%s', 'Later')", self::URGENT, self::LATER));
        $this->sql("INSERT INTO data.person (name) VALUES ('Ada'), ('Bea')");
        $this->sql(\sprintf("INSERT INTO data.task_tag VALUES (1, '%s'), (1, '%s'), (2, '%s')", self::URGENT, self::LATER, self::LATER));
        $this->sql("INSERT INTO data.task_member VALUES (1, 1, 'owner'), (1, 2, 'reviewer')");
    }

    public function testTellsAJunctionTable(): void
    {
        $tables = self::getContainer()->get(DatabaseTablesInterface::class);
        self::assertTrue($tables->find('task_tag')?->junction);
        self::assertTrue($tables->find('task_tag')->isAdoptable());
        self::assertFalse($tables->find('task')?->junction);

        $proposals = $this->adopter()->proposeSet(['task_tag', 'task', 'tag']);
        $junction = $proposals[2]->junction;
        self::assertSame('task_tag', $proposals[2]->table->name);
        self::assertEquals(new JunctionMapping('task_id', 'tag_id', 'tags', 'Tags', false), $junction);
        self::assertTrue($this->adopter()->proposeSet(['task_member', 'task', 'person'])[2]->junction?->asObject);

        // Alone, it links nothing it could point at.
        self::assertSame(['Table task_tag links task, tag: adopt it with them.'], $this->reasons(fn () => $this->adopter()->propose('task_tag')));
    }

    public function testAdoptsAJunctionAsTheLinksOfAManyToManyField(): void
    {
        $reports = $this->adopter()->adoptSet($this->requests(['task', 'tag', 'task_tag']));
        self::assertSame(['task', 'tag', 'task'], array_map(static fn ($report): string => $report->objectName, $reports));

        $registry = self::getContainer()->get(MetadataRegistryInterface::class);
        self::assertFalse($registry->hasObject('task_tag'));
        $tags = $registry->getObject('task')->field('tags')->manyToMany();
        self::assertNotNull($tags);
        self::assertSame(['tag', 'unlink'], [$tags->target, $tags->onDelete->value]);
        self::assertNull(self::getContainer()->get(DatabaseTablesInterface::class)->find('task_tag'));

        $finder = self::getContainer()->get(RecordFinderInterface::class);
        self::assertEqualsCanonicalizing([self::URGENT, self::LATER], $finder->find('task', '1')?->get('tags'));
        self::assertSame([self::LATER], $finder->find('task', '2')?->get('tags'));
        // Written by the application from now on.
        $records = self::getContainer()->get(RecordManagerInterface::class);
        $records->update('2', new RecordInput('task', ['tags' => [self::URGENT]]));
        self::assertSame([self::URGENT], $finder->find('task', '2')?->get('tags'));

        $synchronizer = self::getContainer()->get(SchemaSynchronizerInterface::class);
        self::assertSame([], $synchronizer->plan('task')->statements);
        self::assertSame([], $synchronizer->plan('task')->skipped);
    }

    public function testAdoptsAJunctionWithColumnsAsAJunctionObject(): void
    {
        $this->adopter()->adoptSet($this->requests(['task', 'person', 'task_member']));

        $registry = self::getContainer()->get(MetadataRegistryInterface::class);
        $member = $registry->getObject('task_member');
        self::assertSame(KeyType::Integer, $member->keyType);
        self::assertSame(['task', 'person', 'role'], array_keys($member->fields));
        self::assertSame([['task', 'person']], $member->uniqueTogether);
        $persons = $registry->getObject('task')->field('persons')->manyToMany();
        self::assertNotNull($persons);
        self::assertSame(['person', 'task_member', 'task', 'person'], [$persons->target, $persons->through, $persons->throughSource, $persons->throughTarget]);

        $finder = self::getContainer()->get(RecordFinderInterface::class);
        self::assertEqualsCanonicalizing(['1', '2'], $finder->find('task', '1')?->get('persons'));
        // Each row a record, numbered.
        self::assertSame(['owner', 'reviewer'], array_map(static fn ($row): mixed => $row->get('role'), $finder->findReferencing('task_member', 'task', '1')));
        self::assertEquals(2, $this->connection()->fetchOne('SELECT MAX(id) FROM data.c_task_member'));

        $synchronizer = self::getContainer()->get(SchemaSynchronizerInterface::class);
        foreach (['task', 'person', 'task_member'] as $object) {
            self::assertSame([], $synchronizer->plan($object)->statements, $object);
        }
    }

    /**
     * The most common shape: a key of its own, the pair unique. A junction object already: the
     * many-to-many field is proposed going through it.
     */
    public function testATableWithAKeyOfItsOwnAndAUniquePairIsAJunctionObject(): void
    {
        $this->sql('CREATE TABLE data.assignment (
            id serial PRIMARY KEY,
            task_id integer NOT NULL REFERENCES data.task (id) ON DELETE CASCADE,
            person_id integer NOT NULL REFERENCES data.person (id) ON DELETE CASCADE,
            hours numeric(5,1),
            UNIQUE (task_id, person_id)
        )');
        $this->sql('INSERT INTO data.assignment (task_id, person_id, hours) VALUES (1, 2, 3.5), (2, 1, 1)');

        $proposals = $this->adopter()->proposeSet(['task', 'person', 'assignment']);
        self::assertEquals(new JunctionMapping('task_id', 'person_id', 'persons', 'Persons', true), $proposals[2]->junction);
        $this->adopter()->adoptSet(array_map(static fn (AdoptionProposal $proposal): AdoptionRequest => $proposal->request(), $proposals));

        $registry = self::getContainer()->get(MetadataRegistryInterface::class);
        $assignment = $registry->getObject('assignment');
        // Its own key kept: no key added.
        self::assertSame(KeyType::Integer, $assignment->keyType);
        self::assertSame(['task_id', 'person_id', 'hours'], array_keys($assignment->fields));
        self::assertSame([['task_id', 'person_id']], $assignment->uniqueTogether);
        $persons = $registry->getObject('task')->field('persons')->manyToMany();
        self::assertNotNull($persons);
        self::assertSame(['person', 'assignment', 'task_id', 'person_id'], [$persons->target, $persons->through, $persons->throughSource, $persons->throughTarget]);
        self::assertSame(['2'], self::getContainer()->get(RecordFinderInterface::class)->find('task', '1')?->get('persons'));
        self::assertSame([], self::getContainer()->get(SchemaSynchronizerInterface::class)->plan('assignment')->statements);
    }

    public function testATableWithAKeyOfItsOwnStaysAnObjectWhenAsked(): void
    {
        $this->sql('CREATE TABLE data.assignment (id serial PRIMARY KEY, task_id integer REFERENCES data.task (id), person_id integer REFERENCES data.person (id), UNIQUE (task_id, person_id))');
        // The pair not unique: two rows could link the same two records, no many-to-many field.
        $this->sql('CREATE TABLE data.visit (id serial PRIMARY KEY, task_id integer REFERENCES data.task (id), person_id integer REFERENCES data.person (id))');

        $proposals = $this->adopter()->proposeSet(['task', 'person', 'assignment', 'visit']);
        self::assertNull($proposals[3]->junction);
        $requests = array_map(static fn (AdoptionProposal $proposal): AdoptionRequest => $proposal->request(), $proposals);
        // No many-to-many field: an object with its two relations.
        $assignment = $requests[2];
        $requests[2] = new AdoptionRequest($assignment->table, $assignment->objectName, $assignment->labelSingular, $assignment->labelPlural, $assignment->columns);
        $this->adopter()->adoptSet(array_values($requests));

        $registry = self::getContainer()->get(MetadataRegistryInterface::class);
        self::assertFalse($registry->getObject('task')->hasField('persons'));
        self::assertSame('task', $registry->getObject('assignment')->field('task_id')->relation()?->target);
        self::assertSame([['task_id', 'person_id']], $registry->getObject('assignment')->uniqueTogether);
        self::assertSame('task', $registry->getObject('visit')->field('task_id')->relation()?->target);
    }

    public function testRefusesALinkTableThatWouldLoseColumns(): void
    {
        $requests = $this->requests(['task', 'person', 'task_member']);
        $junction = $requests[2]->junction ?? self::fail('A junction.');
        $requests[2] = new AdoptionRequest('task_member', '', '', '', [], new JunctionMapping($junction->sourceColumn, $junction->targetColumn, 'persons', 'Persons', false));

        self::assertSame(['As the links of a many-to-many field, its columns role would be lost: make it a junction object.'], $this->reasons(fn () => $this->adopter()->adoptSet($requests)));
    }

    /**
     * @param list<string> $tables
     *
     * @return list<AdoptionRequest>
     */
    private function requests(array $tables): array
    {
        return array_map(static fn (AdoptionProposal $proposal): AdoptionRequest => $proposal->request(), $this->adopter()->proposeSet($tables));
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
