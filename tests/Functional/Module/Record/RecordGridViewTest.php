<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\CreatesPetObject;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The record grid laid out by grid views: the columns they list, their renderers, virtual
 * columns filtered and sorted on their fields, the default view at /o/<object> and the
 * others at /o/<object>/grid/<name>.
 */
final class RecordGridViewTest extends WebTestCase
{
    use CreatesPetObject;
    use SignsIn;

    private const string MAIN = <<<'YAML'
        columns:
          - { name: tag, label: Tag, template: '{name} #{chip_code}' }
          - { field: species, renderer: badge, options: { tones: { dog: success } } }
          - { field: born_on, label: Birthday, renderer: date, options: { format: d/m/Y } }
          - { field: owner_email, renderer: truncate, options: { length: 5 } }
          - { field: weight, hidden: true }
          - { field: age, sortable: false, filter: false }
        sort: { tag: desc }
        page_size: 2
        YAML;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);
        self::createPetObject(self::getContainer());
        $records = self::getContainer()->get(RecordManagerInterface::class);
        foreach ([['Rex', 'A1', 'dog', '2020-05-17'], ['Fido', 'B2', 'dog', null], ['Micia', 'C3', 'cat', null]] as [$name, $chip, $species, $born]) {
            $records->create(new RecordInput('pet', ['name' => $name, 'chip_code' => $chip, 'species' => $species, 'born_on' => $born, 'owner_email' => 'owner@example.com', 'weight' => 4]));
        }
        $views = self::getContainer()->get(MetadataManagerInterface::class);
        $views->saveView('pet', ViewKind::Grid, 'main', self::MAIN);
        $views->saveView('pet', ViewKind::Grid, 'compact', "columns: [name, age]\nsort: { name: desc }");
    }

    public function testTheDefaultViewLaysTheGridOut(): void
    {
        $crawler = $this->client->request('GET', '/o/pet');
        self::assertResponseIsSuccessful();

        // The columns of the view, in order; the hidden one is there, off until turned on.
        $header = $crawler->filter('table[data-gv="record_pet"] thead.gv-thead-columns th[data-col-key]');
        self::assertSame(
            ['tag', 'species', 'born_on', 'owner_email', 'weight', 'age', 'column_6'],
            $header->each(static fn ($th): ?string => $th->attr('data-col-key')),
        );
        self::assertSame('display:none', $header->eq(4)->attr('style'));
        // The "Columns" menu turns it on.
        self::assertSelectorExists('[data-controller~="gridview-visibility"]');
        self::assertSame('Birthday', trim($header->eq(2)->text()));

        // Sorted by the virtual column, descending, two rows a page: Rex, Micia.
        self::assertSame(['Rex #A1', 'Micia #C3'], $this->cells('tag'));
        self::assertSame('Dog', $crawler->filter('#gv-tbody-record_pet td[data-col-key="species"] .gv-badge.gv-badge--tone-success')->text());
        self::assertSame('Cat', $crawler->filter('#gv-tbody-record_pet td[data-col-key="species"] .gv-badge')->eq(1)->text());
        self::assertSame('17/05/2020', $this->cells('born_on')[0]);
        self::assertSame('owner…', $this->cells('owner_email')[0]);
        self::assertSame('owner@example.com', $crawler->filter('#gv-tbody-record_pet td[data-col-key="owner_email"] span')->attr('title'));
    }

    public function testFiltersAndSortsAVirtualColumnOnItsFields(): void
    {
        // The text of "{name} #{chip_code}" is filtered as "Rex A1": the fields, joined.
        $this->client->request('GET', '/o/pet', ['fedaleForm' => ['tag' => 'ex a']]);
        self::assertSame(['Rex #A1'], $this->cells('tag'));

        $this->client->request('GET', '/o/pet', ['sort' => 'tag']);
        self::assertSame(['Fido #B2', 'Micia #C3'], $this->cells('tag'));
    }

    public function testAnotherViewHasAPageOfItsOwn(): void
    {
        $crawler = $this->client->request('GET', '/o/pet/grid/compact');
        self::assertResponseIsSuccessful();
        self::assertSame(['Rex', 'Micia', 'Fido'], $this->cells('name', 'record_pet__compact'));
        // The links of the grid stay on the view's page.
        self::assertStringContainsString('/o/pet/grid/compact?sort=', (string) $crawler->filter('table[data-gv="record_pet__compact"] thead a[href*="sort="]')->attr('href'));
        // The views of the object, side by side.
        self::assertSame(['Main', 'Compact'], $crawler->filter('.md-switch a')->each(static fn ($link): string => $link->text()));
        self::assertSame('/o/pet', $crawler->filter('.md-switch a')->first()->attr('href'));
        self::assertSelectorTextSame('.md-switch__item--current', 'Compact');

        $this->client->request('GET', '/o/pet/grid/ghost');
        self::assertResponseStatusCodeSame(404);
    }

    public function testExportsTheColumnsOfTheView(): void
    {
        $this->client->request('GET', '/o/pet/export', ['format' => 'csv', 'cols' => 'tag,species,owner_email']);

        self::assertResponseIsSuccessful();
        $lines = array_map(trim(...), explode("\n", trim((string) $this->client->getInternalResponse()->getContent(), "\u{FEFF}\n")));
        self::assertSame(['Tag,Species,"Owner email"', '"Rex #A1",Dog,owner…', '"Micia #C3",Cat,owner…', '"Fido #B2",Dog,owner…'], $lines);

        $this->client->request('GET', '/o/pet/grid/compact/export', ['format' => 'csv']);
        self::assertStringStartsWith("\u{FEFF}Name,Age", (string) $this->client->getInternalResponse()->getContent());
    }

    /**
     * @return list<string>
     */
    private function cells(string $column, string $grid = 'record_pet'): array
    {
        return $this->client->getCrawler()
            ->filter(\sprintf('#gv-tbody-%s td[data-col-key="%s"]', $grid, $column))
            ->each(static fn ($cell): string => trim($cell->text()));
    }
}
