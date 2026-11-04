<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Application\Grid\RecordGridSourceInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use Doctrine\DBAL\Connection;
use Fedale\GridviewBundle\DataSource\FilterCriterion;
use Fedale\GridviewBundle\DataSource\FilterOperator;
use Fedale\GridviewBundle\DataSource\GridQuery;
use Fedale\GridviewBundle\DataSource\SortDirection;
use Fedale\GridviewBundle\DataSource\SortOrder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A many-to-many field going through a junction object: its links are the live records of
 * person_tag, read like the links of a link table, written as records of the junction.
 */
final class ManyToManyThroughTest extends KernelTestCase
{
    private MetadataManagerInterface $manager;
    private RecordManagerInterface $records;

    protected function setUp(): void
    {
        $this->manager = self::getContainer()->get(MetadataManagerInterface::class);
        $this->records = self::getContainer()->get(RecordManagerInterface::class);

        $this->manager->createObject('person', 'Person', 'People');
        $this->manager->createField('person', 'name', 'text', 'Name', required: true);
        $this->manager->createObject('tag', 'Tag', 'Tags');
        $this->manager->createField('tag', 'name', 'text', 'Name', required: true);
        $this->manager->createObject('person_tag', 'Person tag', 'Person tags');
        $this->manager->createField('person_tag', 'person', 'relation', 'Person', required: true, settings: ['target' => 'person', 'onDelete' => 'cascade']);
        $this->manager->createField('person_tag', 'tag', 'relation', 'Tag', required: true, settings: ['target' => 'tag', 'onDelete' => 'cascade']);
        $this->manager->createField('person_tag', 'role', 'text', 'Role');
    }

    public function testTheFieldNeedsAJunctionKeepingItsLinksUnique(): void
    {
        $through = ['target' => 'tag', 'through' => 'person_tag', 'throughSource' => 'person', 'throughTarget' => 'tag'];

        $this->assertRefused(fn () => $this->createTags($through), 'make "person" and "tag" of "person_tag" unique together first');
        $this->manager->addUniqueTogether('person_tag', ['person', 'tag', 'role']);

        $this->assertRefused(fn () => $this->createTags(['through' => 'person_tag'] + ['target' => 'tag']), '"through", "throughSource" and "throughTarget" go together');
        $this->assertRefused(fn () => $this->createTags(['onDelete' => 'restrict'] + $through), '"onDelete" does not apply with "through"');
        $this->assertRefused(fn () => $this->createTags(['throughSource' => 'tag', 'throughTarget' => 'person'] + $through), '"tag" must be a relation field of "person_tag" pointing at "person"');
        $this->assertRefused(fn () => $this->createTags(['throughTarget' => 'role'] + $through), '"role" must be a relation field of "person_tag" pointing at "tag"');
        $this->assertRefused(fn () => $this->createTags(['through' => 'tag'] + $through), 'a junction object is one of its own');
        $this->assertRefused(fn () => $this->createTags(['through' => 'nowhere'] + $through), 'the junction object "nowhere" does not exist');
        $this->assertRefused(fn () => $this->manager->createField('person', 'tags', 'many_to_many', 'Tags', required: true, settings: $through), 'it cannot be required yet');

        // A set holding both fields, with others, is enough.
        $this->createTags($through);
        self::assertTrue($this->metadata()->getObject('person')->field('tags')->manyToMany()?->goesThrough());
        // No link table of its own.
        self::assertFalse((bool) $this->connection()->fetchOne("SELECT to_regclass('data.l_person__tags') IS NOT NULL"));
    }

    public function testTheLinksAreTheLiveRecordsOfTheJunction(): void
    {
        $this->createThroughField();
        [$ada, $bob] = [$this->create('person', 'Ada'), $this->create('person', 'Bob')];
        [$vip, $press, $gone] = [$this->create('tag', 'VIP'), $this->create('tag', 'Press'), $this->create('tag', 'Gone')];
        $this->link($ada, $vip, 'owner');
        $removed = $this->link($ada, $press);
        $this->link($ada, $gone);
        $this->link($bob, $press);

        // A junction record deleted is a link removed; a tag deleted takes its junction records along.
        $this->records->delete('person_tag', $removed);
        $this->records->delete('tag', $gone);

        self::assertSame([$vip], $this->finder()->find('person', $ada)?->get('tags'));
        self::assertSame([$press], $this->finder()->latest('person')[0]->get('tags'));

        // The tag lists the people linked to it, through the junction.
        $groups = $this->finder()->referencing('tag', $press);
        $people = array_values(array_filter($groups, static fn ($group): bool => 'person' === $group->objectName));
        self::assertSame(['Bob'], array_map(static fn ($record): string => $record->label, $people[0]->records));

        // The grid shows the labels and filters on the live links only.
        $source = self::getContainer()->get(RecordGridSourceInterface::class);
        $sorted = [new SortOrder('name', SortDirection::Asc)];
        $rows = [...$source->fetch(new GridQuery(source: ['object' => 'person'], sort: $sorted))];
        self::assertSame(['VIP', 'Press'], array_column($rows, 'tags__label'));
        $filtered = new GridQuery(source: ['object' => 'person'], criteria: [new FilterCriterion('tags', FilterOperator::In, [$press])], sort: $sorted);
        self::assertSame(['Bob'], array_column([...$source->fetch($filtered)], 'name'));

        // Deleting a person takes its junction records along (their relation cascades).
        $this->records->delete('person', $ada);
        self::assertFalse($this->connection()->fetchOne('SELECT 1 FROM data.c_person_tag WHERE person = ? AND deleted_at IS NULL', [$ada]));
    }

    public function testTheLinksAreNotWrittenWithTheRecord(): void
    {
        $this->createThroughField();
        $vip = $this->create('tag', 'VIP');
        $ada = $this->create('person', 'Ada');

        try {
            $this->records->update($ada, new RecordInput('person', ['tags' => [$vip]]));
            self::fail('The links were written.');
        } catch (RecordValidationFailed $e) {
            self::assertSame(['tags' => ['These links are records of "person_tag": add or remove them there.']], $e->errors);
        }
        // The other fields are written as usual.
        $this->records->update($ada, new RecordInput('person', ['name' => 'Ada L.']));
        self::assertSame('Ada L.', $this->finder()->find('person', $ada)?->get('name'));
    }

    public function testTheJunctionFollowsRenamesAndStaysInUse(): void
    {
        $this->createThroughField();
        $ada = $this->create('person', 'Ada');
        $this->link($ada, $vip = $this->create('tag', 'VIP'));

        $this->assertRefused(fn () => $this->manager->archiveObject('person_tag'), 'many-to-many field "person.tags" goes through it');
        $this->assertRefused(fn () => $this->manager->archiveField('person_tag', 'tag'), 'many-to-many field "person.tags" goes through it');
        $this->assertRefused(fn () => $this->manager->removeUniqueTogether('person_tag', ['person', 'tag']), 'many-to-many field "person.tags" goes through it');

        $this->manager->renameField('person_tag', 'tag', 'label');
        $this->manager->renameObject('person_tag', 'tagging');
        $settings = $this->metadata()->getObject('person')->field('tags')->manyToMany();
        self::assertSame(['tagging', 'person', 'label'], [$settings?->through, $settings?->throughSource, $settings?->throughTarget]);
        self::assertSame([$vip], $this->finder()->find('person', $ada)?->get('tags'));

        // Once the field is gone, the junction is an object like any other.
        $this->manager->archiveField('person', 'tags');
        $this->manager->archiveObject('tagging');
        $this->assertRefused(fn () => $this->manager->restoreField('person', 'tags'), 'it goes through object "tagging", which is archived');
        $this->assertRefused(fn () => $this->manager->purgeObject('tagging'), 'many-to-many field "person.tags" (archived) goes through it');
    }

    private function createThroughField(): void
    {
        $this->manager->addUniqueTogether('person_tag', ['person', 'tag']);
        $this->createTags(['target' => 'tag', 'through' => 'person_tag', 'throughSource' => 'person', 'throughTarget' => 'tag', 'inverseName' => 'people', 'inverseLabel' => 'People']);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function createTags(array $settings): void
    {
        $this->manager->createField('person', 'tags', 'many_to_many', 'Tags', settings: $settings);
    }

    private function create(string $object, string $name): string
    {
        return $this->records->create(new RecordInput($object, ['name' => $name]))->id;
    }

    private function link(string $person, string $tag, ?string $role = null): string
    {
        return $this->records->create(new RecordInput('person_tag', ['person' => $person, 'tag' => $tag, 'role' => $role]))->id;
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

    private function finder(): RecordFinderInterface
    {
        return self::getContainer()->get(RecordFinderInterface::class);
    }

    private function metadata(): MetadataRegistryInterface
    {
        return self::getContainer()->get(MetadataRegistryInterface::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
