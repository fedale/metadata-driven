<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Search;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Application\Grid\RecordGridSourceInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Search\Contract\SearchConfigurationInterface;
use Fedale\GridviewBundle\DataSource\GridQuery;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A relation or a many-to-many field among the searched fields is searched on the labels of the
 * live records it points at; it is searched only when configured, never by default.
 */
final class LinkedLabelSearchTest extends KernelTestCase
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
        $this->manager->createObject('course', 'Course', 'Courses');
        $this->manager->createField('course', 'title', 'text', 'Title', required: true);
        $this->manager->createField('course', 'owner', 'relation', 'Owner', settings: ['target' => 'person']);
        $this->manager->createField('course', 'tags', 'many_to_many', 'Tags', settings: ['target' => 'tag']);
        // The same tags, through a junction object.
        $this->manager->createObject('course_topic', 'Course topic', 'Course topics');
        $this->manager->createField('course_topic', 'course', 'relation', 'Course', required: true, settings: ['target' => 'course', 'onDelete' => 'cascade']);
        $this->manager->createField('course_topic', 'tag', 'relation', 'Tag', required: true, settings: ['target' => 'tag', 'onDelete' => 'cascade']);
        $this->manager->addUniqueTogether('course_topic', ['course', 'tag']);
        $this->manager->createField('course', 'topics', 'many_to_many', 'Topics', settings: ['target' => 'tag', 'through' => 'course_topic', 'throughSource' => 'course', 'throughTarget' => 'tag']);

        [$ada, $bob] = [$this->create('person', 'Ada Lovelace'), $this->create('person', 'Bob Marley')];
        [$php, $docker, $gone, $music] = [$this->create('tag', 'PHP'), $this->create('tag', 'Docker'), $this->create('tag', 'Legacy'), $this->create('tag', 'Music')];
        $this->records->create(new RecordInput('course', ['title' => 'Web development', 'owner' => $ada, 'tags' => [$php, $docker, $gone]]));
        $this->records->create(new RecordInput('course', ['title' => 'Containers', 'owner' => $bob, 'tags' => [$docker]]));
        $this->records->create(new RecordInput('course', ['title' => 'Reggae history', 'owner' => $bob, 'topics' => [['tag' => $music]]]));
        $this->records->delete('tag', $gone);
    }

    public function testLinkedFieldsAreNotSearchedByDefault(): void
    {
        self::assertSame(['title'], $this->fields());
        self::assertSame([], $this->search('php'));
    }

    public function testConfiguredLinkedFieldsAreSearchedOnTheirLabels(): void
    {
        $this->manager->configureSearch('course', null, ['title', 'owner', 'tags', 'topics']);

        self::assertSame(['Web development'], $this->search('php'));
        self::assertSame(['Containers', 'Web development'], $this->search('docker'));
        self::assertSame(['Containers', 'Reggae history'], $this->search('marley'));
        self::assertSame(['Reggae history'], $this->search('mus'), 'the label of a record linked through a junction');
        // Every word must match a field: a title, a tag, an owner.
        self::assertSame(['Web development'], $this->search('docker lovelace'));
        self::assertSame(['Containers'], $this->search('containers docker'));
        // A deleted tag is no longer linked.
        self::assertSame([], $this->search('legacy'));

        // The phrase handler matches one field, a linked label included.
        $this->manager->configureSearch('course', 'phrase', ['title', 'owner']);
        self::assertSame(['Web development'], $this->search('ada love'));
        self::assertSame([], $this->search('love ada'));
    }

    /**
     * @return list<string> the titles of the matching courses, sorted
     */
    private function search(string $term): array
    {
        $query = new GridQuery(source: ['object' => 'course'], searchTerm: $term, searchFields: $this->fields());
        $titles = [];
        foreach (self::getContainer()->get(RecordGridSourceInterface::class)->fetch($query) as $row) {
            $titles[] = \is_string($row['title'] ?? null) ? $row['title'] : '';
        }
        sort($titles);

        return $titles;
    }

    /**
     * @return list<string>
     */
    private function fields(): array
    {
        $course = self::getContainer()->get(MetadataRegistryInterface::class)->getObject('course');

        return self::getContainer()->get(SearchConfigurationInterface::class)->fieldsFor($course);
    }

    private function create(string $object, string $name): string
    {
        $field = 'person' === $object || 'tag' === $object ? 'name' : 'title';

        return $this->records->create(new RecordInput($object, [$field => $name]))->id;
    }
}
