<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Metadata;

use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Fields unique together: a partial unique index built and dropped with the rule, records
 * checked against it (live ones only), and the rule following renames.
 */
final class UniqueTogetherTest extends KernelTestCase
{
    private MetadataManagerInterface $manager;
    private RecordManagerInterface $records;

    protected function setUp(): void
    {
        $this->manager = self::getContainer()->get(MetadataManagerInterface::class);
        $this->records = self::getContainer()->get(RecordManagerInterface::class);

        $this->manager->createObject('person', 'Person', 'People');
        $this->manager->createField('person', 'name', 'text', 'Name', required: true);
        $this->manager->createObject('team', 'Team', 'Teams');
        $this->manager->createField('team', 'name', 'text', 'Name', required: true);
        // A junction object: a person in a team, with a role.
        $this->manager->createObject('membership', 'Membership', 'Memberships');
        $this->manager->createField('membership', 'person', 'relation', 'Person', required: true, settings: ['target' => 'person', 'onDelete' => 'cascade']);
        $this->manager->createField('membership', 'team', 'relation', 'Team', settings: ['target' => 'team', 'onDelete' => 'cascade']);
        $this->manager->createField('membership', 'role', 'text', 'Role');
    }

    public function testBuildsAPartialUniqueIndexAndDropsItWithTheRule(): void
    {
        $this->manager->addUniqueTogether('membership', ['person', 'team']);

        self::assertSame([['person', 'team']], $this->metadata()->getObject('membership')->uniqueTogether);
        self::assertSame(
            ['uniqc_c_membership__person__team' => 'CREATE UNIQUE INDEX uniqc_c_membership__person__team ON data.c_membership USING btree (person, team) WHERE (deleted_at IS NULL)'],
            $this->uniqueTogetherIndexes('c_membership'),
        );
        // The table matches the metadata: the comparison does not see the index.
        self::assertTrue($this->schema()->plan('membership')->isEmpty());

        $this->manager->removeUniqueTogether('membership', ['team', 'person']);
        self::assertSame([], $this->metadata()->getObject('membership')->uniqueTogether);
        self::assertSame([], $this->uniqueTogetherIndexes('c_membership'));
        self::assertTrue($this->schema()->plan('membership')->isEmpty());
    }

    public function testRefusesWhatCannotBeUniqueTogether(): void
    {
        $this->manager->createField('membership', 'secret', 'password', 'Secret');
        $this->manager->createField('membership', 'badge', 'file', 'Badge');
        $this->manager->createField('membership', 'tags', 'many_to_many', 'Tags', settings: ['target' => 'team']);

        $this->assertRefused(fn () => $this->manager->addUniqueTogether('membership', ['person']), 'Name at least two fields.');
        $this->assertRefused(fn () => $this->manager->addUniqueTogether('membership', ['person', 'person']), 'name at least two different fields');
        $this->assertRefused(fn () => $this->manager->addUniqueTogether('membership', ['person', 'nope']), 'Object "membership" has no field "nope".');
        $this->assertRefused(fn () => $this->manager->addUniqueTogether('membership', ['person', 'secret']), 'Field "secret" is write-only');
        $this->assertRefused(fn () => $this->manager->addUniqueTogether('membership', ['person', 'badge']), 'Field "badge" is of type "file", which cannot be unique.');
        $this->assertRefused(fn () => $this->manager->addUniqueTogether('membership', ['person', 'tags']), 'Field "tags" links several records (many-to-many): it cannot be unique.');
        $this->assertRefused(fn () => $this->manager->removeUniqueTogether('membership', ['person', 'team']), 'Fields (person, team) of "membership" are not unique together.');

        $this->manager->addUniqueTogether('membership', ['person', 'team']);
        $this->assertRefused(fn () => $this->manager->addUniqueTogether('membership', ['team', 'person']), 'these fields are already unique together');
    }

    public function testRefusesTheRuleWhileRecordsBreakIt(): void
    {
        [$ada, $red] = [$this->person('Ada'), $this->team('Red')];
        foreach (['lead', 'member', 'guest'] as $role) {
            $this->membership($ada, $red, $role);
        }
        // One of them is deleted: it no longer counts.
        $this->records->delete('membership', $this->membership($ada, $red, 'former'));
        // An empty team never clashes.
        $this->membership($ada, null);
        $this->membership($ada, null);

        $this->assertRefused(
            fn () => $this->manager->addUniqueTogether('membership', ['person', 'team']),
            'Fields (person, team) of "membership" cannot be unique together: 3 records share their values with another one; change or delete them first.',
        );
        self::assertSame([], $this->uniqueTogetherIndexes('c_membership'));
    }

    public function testRecordsAreCheckedAgainstTheLiveOnes(): void
    {
        $this->manager->addUniqueTogether('membership', ['person', 'team']);
        [$ada, $bob, $red, $blue] = [$this->person('Ada'), $this->person('Bob'), $this->team('Red'), $this->team('Blue')];
        $first = $this->membership($ada, $red);

        $this->assertInvalid(fn () => $this->membership($ada, $red), ['person' => ['Another record already has the same person and team.']]);
        $second = $this->membership($ada, $blue);
        $this->membership($bob, $red);
        // An update carrying one of the fields is checked with the stored value of the other.
        $this->assertInvalid(
            fn () => $this->records->update($second, new RecordInput('membership', ['team' => $red])),
            ['team' => ['Another record already has the same person and team.']],
        );
        // A field the set does not hold changes freely.
        $this->records->update($first, new RecordInput('membership', ['role' => 'lead']));
        // Empty values never clash.
        $this->membership($ada, null);
        $this->membership($ada, null);

        // A deleted record frees its values.
        $this->records->delete('membership', $first);
        $this->membership($ada, $red);
    }

    public function testTheIndexRefusesWhatTheCheckMisses(): void
    {
        $this->manager->addUniqueTogether('membership', ['person', 'team']);
        [$ada, $red] = [$this->person('Ada'), $this->team('Red')];
        $this->membership($ada, $red);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection()->executeStatement(
            'INSERT INTO data.c_membership (id, created_at, updated_at, person, team) VALUES (gen_random_uuid(), NOW(), NOW(), ?, ?)',
            [$ada, $red],
        );
    }

    public function testTheRuleFollowsRenamesAndKeepsItsFieldsActive(): void
    {
        $this->manager->addUniqueTogether('membership', ['person', 'team']);

        $this->manager->renameField('membership', 'person', 'member');
        self::assertSame([['member', 'team']], $this->metadata()->getObject('membership')->uniqueTogether);
        self::assertSame(['uniqc_c_membership__member__team'], array_keys($this->uniqueTogetherIndexes('c_membership')));

        $this->manager->renameObject('membership', 'enrolment');
        self::assertSame(['uniqc_c_enrolment__member__team'], array_keys($this->uniqueTogetherIndexes('c_enrolment')));
        self::assertTrue($this->schema()->plan('enrolment')->isEmpty());

        $this->assertRefused(fn () => $this->manager->archiveField('enrolment', 'team'), 'it is unique together with "member"');
    }

    public function testTheConsoleAddsListsAndRemovesARule(): void
    {
        $application = new Application(self::bootKernel());
        $unique = new CommandTester($application->find('metadata:object:unique'));

        self::assertSame(0, $unique->execute(['object' => 'membership', 'fields' => 'person, team']));
        self::assertStringContainsString('Fields person, team of "membership" are unique together', $unique->getDisplay());

        $list = new CommandTester($application->find('metadata:list'));
        $list->execute(['object' => 'membership']);
        self::assertStringContainsString('Unique together', $list->getDisplay());
        self::assertStringContainsString('person, team', $list->getDisplay());

        self::assertSame(2, $unique->execute(['object' => 'membership', 'fields' => 'person']));
        self::assertSame(1, $unique->execute(['object' => 'membership', 'fields' => 'team,person']));
        self::assertSame(0, $unique->execute(['object' => 'membership', 'fields' => 'team,person', '--remove' => true]));
        self::assertSame([], self::getContainer()->get(MetadataRegistryInterface::class)->getObject('membership')->uniqueTogether);
    }

    private function person(string $name): string
    {
        return $this->records->create(new RecordInput('person', ['name' => $name]))->id;
    }

    private function team(string $name): string
    {
        return $this->records->create(new RecordInput('team', ['name' => $name]))->id;
    }

    private function membership(string $person, ?string $team, ?string $role = null): string
    {
        return $this->records->create(new RecordInput('membership', ['person' => $person, 'team' => $team, 'role' => $role]))->id;
    }

    /**
     * @return array<string, string> index name => definition
     */
    private function uniqueTogetherIndexes(string $table): array
    {
        /** @var array<string, string> $indexes */
        $indexes = $this->connection()->fetchAllKeyValue(
            "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'data' AND tablename = ? AND indexname LIKE 'uniqc\\_%' ORDER BY indexname",
            [$table],
        );

        return $indexes;
    }

    private function assertRefused(callable $change, string $message): void
    {
        try {
            $change();
            self::fail('The change was not refused.');
        } catch (MetadataException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function assertInvalid(callable $write, array $errors): void
    {
        try {
            $write();
            self::fail('The write was not refused.');
        } catch (RecordValidationFailed $e) {
            self::assertSame($errors, $e->errors);
        }
    }

    private function metadata(): MetadataRegistryInterface
    {
        return self::getContainer()->get(MetadataRegistryInterface::class);
    }

    private function schema(): SchemaSynchronizerInterface
    {
        return self::getContainer()->get(SchemaSynchronizerInterface::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
