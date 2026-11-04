<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A many-to-many field going through a junction object, through HTTP: the record form shows
 * its links without editing them, the grid lists and filters them.
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
        $manager->addUniqueTogether('person_tag', ['person', 'tag']);
        $manager->createField('person', 'tags', 'many_to_many', 'Tags', settings: ['target' => 'tag', 'through' => 'person_tag', 'throughSource' => 'person', 'throughTarget' => 'tag']);
    }

    public function testTheFormShowsTheLinksAndSavesTheOtherFields(): void
    {
        $ada = $this->create('person', 'Ada');
        $vip = $this->create('tag', 'VIP');
        $this->records()->create(new RecordInput('person_tag', ['person' => $ada, 'tag' => $vip]));

        $crawler = $this->client->request('GET', \sprintf('/o/person/%s/edit', $ada));
        self::assertResponseIsSuccessful();
        $select = $crawler->filter('select[name="record[tags][]"]');
        self::assertSame('disabled', $select->attr('disabled'));
        self::assertSame(['VIP'], $select->filter('option[selected]')->each(static fn ($option): string => trim($option->text())));
        self::assertSelectorTextContains('[data-gv-field="tags"]', 'Added and removed as records of Person tags.');

        $this->client->submitForm('Save', ['record[name]' => 'Ada L.']);
        self::assertResponseRedirects();
        $person = self::getContainer()->get(RecordFinderInterface::class)->find('person', $ada);
        self::assertSame('Ada L.', $person?->get('name'));
        self::assertSame([$vip], $person->get('tags'));
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
