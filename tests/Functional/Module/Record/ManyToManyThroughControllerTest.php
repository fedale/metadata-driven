<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A many-to-many field going through a junction object, through HTTP: the record form edits
 * its rows inline (the target and the junction's other fields), as far as the permissions on
 * the junction go; the grid lists and filters the links.
 */
final class ManyToManyThroughControllerTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);

        $manager = self::getContainer()->get(MetadataManagerInterface::class);
        $manager->createObject('person', 'Person', 'People');
        $manager->createField('person', 'name', 'text', 'Name', required: true);
        $manager->createObject('tag', 'Tag', 'Tags');
        $manager->createField('tag', 'name', 'text', 'Name', required: true);
        $manager->createObject('person_tag', 'Person tag', 'Person tags');
        $manager->createField('person_tag', 'person', 'relation', 'Person', required: true, settings: ['target' => 'person', 'onDelete' => 'cascade']);
        $manager->createField('person_tag', 'tag', 'relation', 'Tag', required: true, settings: ['target' => 'tag', 'onDelete' => 'cascade']);
        $manager->createField('person_tag', 'role', 'text', 'Role');
        $manager->addUniqueTogether('person_tag', ['person', 'tag']);
        $manager->createField('person', 'tags', 'many_to_many', 'Tags', settings: ['target' => 'tag', 'through' => 'person_tag', 'throughSource' => 'person', 'throughTarget' => 'tag', 'max' => 3]);
    }

    public function testTheFormEditsTheRowsInline(): void
    {
        [$vip, $press] = [$this->create('tag', 'VIP'), $this->create('tag', 'Press')];
        $ada = $this->create('person', 'Ada');
        $row = $this->records()->create(new RecordInput('person_tag', ['person' => $ada, 'tag' => $vip, 'role' => 'owner']))->id;

        $crawler = $this->client->request('GET', \sprintf('/o/person/%s/edit', $ada));
        self::assertResponseIsSuccessful();
        $collection = $crawler->filter('[data-gv-field="tags"] .gv-collection');
        self::assertSame('__tags__', $collection->attr('data-gridview-collection-prototype-name-value'));
        self::assertSame('3', $collection->attr('data-gridview-collection-max-value'));
        self::assertStringContainsString('record[tags][__tags__][values][tag]', (string) $collection->attr('data-gridview-collection-prototype-value'));
        // One row per link: its junction record, the target first, then the junction's fields.
        self::assertSame($row, $crawler->filter('input[name="record[tags][0][id]"]')->attr('value'));
        self::assertSame(['VIP'], $crawler->filter('select[name="record[tags][0][values][tag]"] option[selected]')->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertSame('owner', $crawler->filter('input[name="record[tags][0][values][role]"]')->attr('value'));
        self::assertCount(0, $crawler->filter('[name="record[tags][0][values][person]"]'));
        self::assertCount(1, $crawler->filter('[data-gv-field="tags"] .gv-collection__add'));

        // Change a row, add one; a row added and left blank is not a link.
        $edit = \sprintf('/o/person/%s/edit', $ada);
        $this->post($edit, $crawler, 'record', ['tags' => [
            0 => ['id' => $row, 'values' => ['tag' => $vip, 'role' => 'editor']],
            1 => ['id' => '', 'values' => ['tag' => $press, 'role' => 'guest']],
            2 => ['id' => '', 'values' => ['tag' => '', 'role' => '']],
        ]]);
        self::assertResponseRedirects();
        self::assertSame([[$vip, 'editor'], [$press, 'guest']], $this->rows($ada));

        // A row removed is a junction record deleted.
        $this->post($edit, $crawler, 'record', ['tags' => [0 => ['id' => $row, 'values' => ['tag' => $vip, 'role' => 'editor']]]]);
        self::assertSame([[$vip, 'editor']], $this->rows($ada));
    }

    public function testANewRecordComesWithItsRowsAndTheErrorsGoOnTheirRow(): void
    {
        [$vip, $press] = [$this->create('tag', 'VIP'), $this->create('tag', 'Press')];

        $crawler = $this->client->request('GET', '/o/person/new');
        $row = static fn (string $tag, string $role): array => ['id' => '', 'values' => ['tag' => $tag, 'role' => $role]];
        $this->post('/o/person/new', $crawler, 'record', ['name' => 'Ada', 'tags' => [$row($vip, 'owner'), $row($vip, 'guest')]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.gv-collection__row:nth-child(2)', 'This record is already linked.');
        self::assertCount(0, self::getContainer()->get(RecordFinderInterface::class)->latest('person'));

        $this->post('/o/person/new', $crawler, 'record', ['name' => 'Ada', 'tags' => [$row($vip, 'owner'), $row($press, 'guest')]]);
        self::assertResponseRedirects('/o/person', 303);
        $ada = self::getContainer()->get(RecordFinderInterface::class)->latest('person')[0]->id;
        self::assertSame([[$vip, 'owner'], [$press, 'guest']], $this->rows($ada));
    }

    public function testThePermissionsOnTheJunctionDecideWhatIsShown(): void
    {
        $ada = $this->create('person', 'Ada');
        $vip = $this->create('tag', 'VIP');
        $this->records()->create(new RecordInput('person_tag', ['person' => $ada, 'tag' => $vip, 'role' => 'owner']));
        $person = ['record.person.read', 'record.person.update', 'record.tag.read'];

        // Read only: the rows, disabled, without buttons; what the browser sends is left out.
        self::signIn($this->client, 'reader@example.com', [...$person, 'record.person_tag.read']);
        $crawler = $this->client->request('GET', \sprintf('/o/person/%s/edit', $ada));
        self::assertSame('owner', $crawler->filter('input[name="record[tags][0][values][role]"]')->attr('value'));
        self::assertSame('disabled', $crawler->filter('input[name="record[tags][0][values][role]"]')->attr('disabled'));
        self::assertCount(0, $crawler->filter('[data-gv-field="tags"] .gv-collection__add, [data-gv-field="tags"] .gv-collection__remove'));
        $this->post(\sprintf('/o/person/%s/edit', $ada), $crawler, 'record', ['name' => 'Ada L.', 'tags' => []]);
        self::assertResponseRedirects();
        self::assertSame([[$vip, 'owner']], $this->rows($ada));

        // Every right on the junction, but not on what deleting one of its records cascades to.
        $manager = self::getContainer()->get(MetadataManagerInterface::class);
        $manager->createObject('tag_note', 'Tag note', 'Tag notes');
        $manager->createField('tag_note', 'link', 'relation', 'Link', settings: ['target' => 'person_tag', 'onDelete' => 'cascade']);
        $junction = ['record.person_tag.read', 'record.person_tag.create', 'record.person_tag.update', 'record.person_tag.delete'];
        self::signIn($this->client, 'partial@example.com', [...$person, ...$junction]);
        $crawler = $this->client->request('GET', \sprintf('/o/person/%s/edit', $ada));
        self::assertSame('disabled', $crawler->filter('input[name="record[tags][0][values][role]"]')->attr('disabled'));
        self::signIn($this->client, 'editor@example.com', [...$person, ...$junction, 'record.tag_note.delete']);
        $crawler = $this->client->request('GET', \sprintf('/o/person/%s/edit', $ada));
        self::assertNull($crawler->filter('input[name="record[tags][0][values][role]"]')->attr('disabled'));

        // No read: the labels of the linked records only, as the grid shows them.
        self::signIn($this->client, 'stranger@example.com', $person);
        $crawler = $this->client->request('GET', \sprintf('/o/person/%s/edit', $ada));
        self::assertSame(['VIP'], $crawler->filter('select[name="record[tags][]"] option[selected]')->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertSame('disabled', $crawler->filter('select[name="record[tags][]"]')->attr('disabled'));
        self::assertCount(0, $crawler->filter('[name*="[values][role]"]'));
        self::assertSelectorTextContains('[data-gv-field="tags"]', 'Added and removed as records of Person tags.');
    }

    public function testACompositeFormEditsTheRowsToo(): void
    {
        [$vip, $press] = [$this->create('tag', 'VIP'), $this->create('tag', 'Press')];
        self::getContainer()->get(MetadataManagerInterface::class)->saveForm('person_card', "label: Person card\nobject: person\nfields: [name, tags]\n");

        $crawler = $this->client->request('GET', '/f/person_card/new');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-gv-field="tags"] .gv-collection'));
        $owner = ['id' => '', 'values' => ['tag' => $vip, 'role' => 'owner']];
        $this->post('/f/person_card/new', $crawler, 'composite', ['values' => ['name' => 'Ada', 'tags' => [$owner, ['id' => '', 'values' => ['role' => 'guest']]]]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.gv-collection__row:nth-child(2)', 'This value is required.');

        $this->post('/f/person_card/new', $crawler, 'composite', ['values' => ['name' => 'Ada', 'tags' => [$owner, ['id' => '', 'values' => ['tag' => $press, 'role' => 'guest']]]]]);
        self::assertResponseRedirects();
        $ada = self::getContainer()->get(RecordFinderInterface::class)->latest('person')[0]->id;
        self::assertSame([[$vip, 'owner'], [$press, 'guest']], $this->rows($ada));

        // Editing shows the rows of the record.
        $crawler = $this->client->request('GET', \sprintf('/f/person_card/%s/edit', $ada));
        self::assertSame('owner', $crawler->filter('input[name="composite[values][tags][0][values][role]"]')->attr('value'));
    }

    public function testTheGridListsAndFiltersTheLinks(): void
    {
        [$ada, $bob] = [$this->create('person', 'Ada'), $this->create('person', 'Bob')];
        [$vip, $press] = [$this->create('tag', 'VIP'), $this->create('tag', 'Press')];
        foreach ([[$ada, $vip], [$ada, $press], [$bob, $press]] as [$person, $tag]) {
            $this->records()->create(new RecordInput('person_tag', ['person' => $person, 'tag' => $tag]));
        }

        $this->client->request('GET', '/o/person', ['sort' => 'name']);
        self::assertSame(['Press, VIP', 'Press'], $this->cells('tags'));

        $this->client->request('GET', '/o/person', ['sort' => 'name', 'fedaleForm' => ['tags' => [$vip]]]);
        self::assertSame(['Ada'], $this->cells('name'));
    }

    /**
     * Posts the form of the page with some of its inputs changed: each key of $changes replaces
     * that input (or group of inputs) of the form named $form, whole.
     *
     * @param array<string, mixed> $changes
     */
    private function post(string $uri, Crawler $crawler, string $form, array $changes): void
    {
        $values = $crawler->selectButton('Save')->form()->getPhpValues();
        $current = \is_array($values[$form] ?? null) ? $values[$form] : [];
        $values[$form] = $changes + $current;
        $this->client->request('POST', $uri, $values);
    }

    /**
     * @return list<array{mixed, mixed}> target and role of each row, oldest first
     */
    private function rows(string $person): array
    {
        return array_map(
            static fn (RecordOutput $row): array => [$row->get('tag'), $row->get('role')],
            self::getContainer()->get(RecordFinderInterface::class)->find('person', $person)?->rows('tags') ?? [],
        );
    }

    /**
     * @return list<string>
     */
    private function cells(string $column): array
    {
        return $this->client->getCrawler()
            ->filter(\sprintf('#gv-tbody-record_person td[data-col-key="%s"]', $column))
            ->each(static fn ($cell): string => trim($cell->text()));
    }

    private function create(string $object, string $name): string
    {
        return $this->records()->create(new RecordInput($object, ['name' => $name]))->id;
    }

    private function records(): RecordManagerInterface
    {
        return self::getContainer()->get(RecordManagerInterface::class);
    }
}
