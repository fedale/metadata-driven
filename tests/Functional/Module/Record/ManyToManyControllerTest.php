<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\CreatesTaggedContacts;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A many-to-many field through HTTP: the form picks any number of records, the linked record
 * lists the records linking to it, the grid shows, filters and exports their labels.
 */
final class ManyToManyControllerTest extends WebTestCase
{
    use SignsIn;
    use CreatesTaggedContacts;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);
        self::createTaggedContacts(self::getContainer());
    }

    public function testCreatesARecordLinkedToSeveralOthers(): void
    {
        $vip = $this->tag('VIP');
        $press = $this->tag('Press');

        $crawler = $this->client->request('GET', '/o/contact/new');
        $select = $crawler->filter('select[name="record[tags][]"]');
        self::assertSame('multiple', $select->attr('multiple'));
        self::assertStringContainsString('autocomplete', (string) $select->attr('data-controller'));
        self::assertSame('/o/tag/lookup', $select->attr('data-symfony--ux-autocomplete--autocomplete-url-value'));

        // The browser sends the ids picked in the autocomplete, which the form did not list.
        $values = $crawler->selectButton('Save')->form()->getPhpValues();
        self::assertIsArray($values['record']);
        $values['record']['full_name'] = 'Anna Rossi';
        $values['record']['tags'] = [$vip, $press];
        $this->client->request('POST', '/o/contact/new', $values);
        self::assertResponseRedirects('/o/contact', 303);

        $anna = self::getContainer()->get(RecordFinderInterface::class)->latest('contact')[0];
        self::assertEqualsCanonicalizing([$vip, $press], $anna->get('tags'));
    }

    public function testEditsTheLinksAndListsThemOnTheLinkedRecord(): void
    {
        $vip = $this->tag('VIP');
        $press = $this->tag('Press');
        $anna = $this->records()->create(new RecordInput('contact', ['full_name' => 'Anna Rossi', 'tags' => [$vip, $press]]));

        $crawler = $this->client->request('GET', \sprintf('/o/contact/%s/edit', $anna->id));
        $chosen = $crawler->filter('select[name="record[tags][]"] option[selected]');
        self::assertEqualsCanonicalizing(['VIP', 'Press'], $chosen->each(static fn ($option): string => trim($option->text())));

        $values = $crawler->selectButton('Save')->form()->getPhpValues();
        self::assertIsArray($values['record']);
        $values['record']['tags'] = [$press];
        $this->client->request('POST', \sprintf('/o/contact/%s/edit', $anna->id), $values);
        self::assertResponseRedirects();
        self::assertSame([$press], self::getContainer()->get(RecordFinderInterface::class)->find('contact', $anna->id)?->get('tags'));

        $crawler = $this->client->request('GET', \sprintf('/o/tag/%s/edit', $press));
        self::assertSelectorTextContains('.md-related h2', 'Contacts');
        self::assertSame(\sprintf('/o/contact/%s/edit', $anna->id), $crawler->filter('.md-related a')->attr('href'));
    }

    public function testRefusesTooManyLinks(): void
    {
        $tags = [$this->tag('A'), $this->tag('B'), $this->tag('C'), $this->tag('D')];

        $crawler = $this->client->request('GET', '/o/contact/new');
        $values = $crawler->selectButton('Save')->form()->getPhpValues();
        self::assertIsArray($values['record']);
        $values['record']['full_name'] = 'Anna Rossi';
        $values['record']['tags'] = $tags;
        $this->client->request('POST', '/o/contact/new', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-gv-field="tags"]', 'Choose at most 3 records.');
    }

    public function testTheGridListsTheLinkedRecordsAndFiltersOnThem(): void
    {
        [$vip, $press] = $this->gridContacts();

        $crawler = $this->client->request('GET', '/o/contact', ['sort' => 'full_name']);
        self::assertResponseIsSuccessful();
        self::assertSame(['Anna Rossi', 'Bruno Bianchi', 'Carla Verdi'], $this->cells('full_name'));
        self::assertSame(['Press, VIP', 'Press', ''], $this->cells('tags'));
        // No order to sort on; filtered on the tags, looked up as the user types.
        $header = $crawler->filter('table[data-gv="record_contact"] thead th[data-col-key="tags"]');
        self::assertCount(0, $header->filter('a[href*="sort="]'));
        self::assertGreaterThan(0, $crawler->filter('table[data-gv="record_contact"] thead th[data-col-key="full_name"] a[href*="sort="]')->count());
        $filter = $crawler->filter('select[name="fedaleForm[tags][]"][multiple]');
        self::assertSame('/o/tag/options', $filter->attr('data-gridview-relation-filter-ajax-url-value'));

        $this->client->request('GET', '/o/contact', ['sort' => 'full_name', 'fedaleForm' => ['tags' => [$vip]]]);
        self::assertSame(['Anna Rossi'], $this->cells('full_name'));
        $this->client->request('GET', '/o/contact', ['sort' => 'full_name', 'fedaleForm' => ['tags' => [$vip, $press]]]);
        self::assertSame(['Anna Rossi', 'Bruno Bianchi'], $this->cells('full_name'));
    }

    public function testExportsTheLabelsOfTheLinkedRecords(): void
    {
        $this->gridContacts();

        $this->client->request('GET', '/o/contact/export', ['format' => 'csv', 'sort' => 'full_name', 'cols' => 'full_name,tags']);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['"Full name",Tags', '"Anna Rossi","Press, VIP"', '"Bruno Bianchi",Press', '"Carla Verdi",'],
            array_map(trim(...), explode("\n", trim((string) $this->client->getInternalResponse()->getContent(), "\u{FEFF}\n"))),
        );
    }

    public function testAGridViewShowsTheLinkedRecords(): void
    {
        $this->gridContacts();
        self::getContainer()->get(MetadataManagerInterface::class)
            ->saveView('contact', ViewKind::Grid, 'main', "columns:\n  - full_name\n  - { field: tags, label: Labels, renderer: truncate, options: { length: 6 } }\nsort: { full_name: asc }\n");

        $crawler = $this->client->request('GET', '/o/contact');
        self::assertResponseIsSuccessful();
        self::assertSame('Labels', trim($crawler->filter('table[data-gv="record_contact"] thead th[data-col-key="tags"]')->text()));
        self::assertSame(['Press,…', 'Press', ''], $this->cells('tags'));
    }

    /**
     * Anna Rossi tagged VIP and Press, Bruno Bianchi Press, Carla Verdi nothing.
     *
     * @return array{string, string} the ids of VIP and Press
     */
    private function gridContacts(): array
    {
        $vip = $this->tag('VIP');
        $press = $this->tag('Press');
        foreach (['Anna Rossi' => [$vip, $press], 'Bruno Bianchi' => [$press], 'Carla Verdi' => []] as $name => $tags) {
            $this->records()->create(new RecordInput('contact', ['full_name' => $name, 'tags' => $tags]));
        }

        return [$vip, $press];
    }

    /**
     * @return list<string>
     */
    private function cells(string $column): array
    {
        return $this->client->getCrawler()
            ->filter(\sprintf('#gv-tbody-record_contact td[data-col-key="%s"]', $column))
            ->each(static fn ($cell): string => trim($cell->text()));
    }

    private function tag(string $name): string
    {
        return $this->records()->create(new RecordInput('tag', ['name' => $name]))->id;
    }

    private function records(): RecordManagerInterface
    {
        return self::getContainer()->get(RecordManagerInterface::class);
    }
}
