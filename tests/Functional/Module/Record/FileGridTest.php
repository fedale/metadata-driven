<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\FieldType\Contract\Dto\FileUpload;
use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * File fields in the record grid: a link to each file (a thumbnail for an image), the
 * "has a file" filter, the export of the names, and the rules of the grid views.
 */
final class FileGridTest extends WebTestCase
{
    use SignsIn;

    private const string PDF = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj << /Type /Catalog >> endobj\n";

    private KernelBrowser $client;
    /** @var array<string, array<string, string|null>> name => field => file id */
    private array $products = [];
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The test storage is in memory: a new kernel per request would lose the stored files.
        $this->client->disableReboot();
        $container = self::getContainer();
        ($container->get(CreateObjectHandler::class))(new CreateObjectCommand('product', 'Product', 'Products'));
        $createField = $container->get(CreateFieldHandler::class);
        $createField(new CreateFieldCommand('product', 'name', 'text', 'Name', isRequired: true));
        $createField(new CreateFieldCommand('product', 'manual', 'file', 'Manual'));
        $createField(new CreateFieldCommand('product', 'photo', 'file', 'Photo', settings: ['image' => true]));
        $createField(new CreateFieldCommand('product', 'leaflets', 'file', 'Leaflets', settings: ['multiple' => true]));
        self::signInAsSuperAdmin($this->client);

        $records = $container->get(RecordManagerInterface::class);
        foreach ([
            'Armchair' => [
                'manual' => new FileUpload($this->file(self::PDF), 'armchair <manual>.pdf'),
                'photo' => new FileUpload($this->png(), 'armchair.png'),
                'leaflets' => [new FileUpload($this->file(self::PDF.'care'), 'care.pdf'), new FileUpload($this->png(), 'colours.png')],
            ],
            'Bench' => ['manual' => new FileUpload($this->file(self::PDF), 'bench.pdf')],
            'Chair' => [],
        ] as $name => $files) {
            $record = $records->create(new RecordInput('product', ['name' => $name, ...$files]));
            $this->products[$name] = array_map(
                static fn (mixed $id): ?string => \is_string($id) ? $id : null,
                ['manual' => $record->values['manual'] ?? null, 'photo' => $record->values['photo'] ?? null],
            );
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testACellLinksToTheFileWithAThumbnailForAnImage(): void
    {
        $crawler = $this->client->request('GET', '/o/product', ['sort' => 'name']);

        self::assertResponseIsSuccessful();
        self::assertSame(['armchair <manual>.pdf', 'bench.pdf', ''], $this->cells('manual'));
        self::assertSame(['armchair.png', '', ''], $this->cells('photo'));

        $manual = $crawler->filter('#gv-tbody-record_product td[data-col-key="manual"] a.md-file-cell')->first();
        self::assertSame('/files/'.$this->products['Armchair']['manual'], $manual->attr('href'));
        self::assertSame('false', $manual->attr('data-turbo'));
        self::assertNull($manual->attr('target'), 'A document is downloaded, not opened in a tab.');
        self::assertCount(1, $manual->filter('svg.md-file-cell__icon'));
        self::assertCount(0, $manual->filter('img'));

        $photo = $crawler->filter('#gv-tbody-record_product td[data-col-key="photo"] a.md-file-cell')->first();
        self::assertSame('_blank', $photo->attr('target'));
        self::assertSame(
            '/files/'.$this->products['Armchair']['photo'].'/thumbnail/small',
            $photo->filter('img.md-file-cell__thumbnail')->attr('src'),
        );

        // No order to sort on; a yes/no filter instead.
        self::assertCount(0, $crawler->filter('table[data-gv="record_product"] thead th[data-col-key="manual"] a[href*="sort="]'));
        self::assertCount(1, $crawler->filter('select[name="fedaleForm[manual]"]'));
    }

    public function testAListShowsEachFileInItsOrder(): void
    {
        $crawler = $this->client->request('GET', '/o/product', ['sort' => 'name']);

        self::assertResponseIsSuccessful();
        self::assertSame(['care.pdf, colours.png', '', ''], $this->cells('leaflets'));
        $links = $crawler->filter('#gv-tbody-record_product td[data-col-key="leaflets"] a.md-file-cell');
        self::assertCount(2, $links);
        self::assertCount(1, $links->eq(0)->filter('svg.md-file-cell__icon'));
        self::assertCount(1, $links->eq(1)->filter('img.md-file-cell__thumbnail'));
        self::assertCount(1, $crawler->filter('select[name="fedaleForm[leaflets]"]'));
        self::assertCount(0, $crawler->filter('table[data-gv="record_product"] thead th[data-col-key="leaflets"] a[href*="sort="]'));

        // The order is the one the record keeps.
        $armchair = array_values(array_filter(
            self::getContainer()->get(RecordFinderInterface::class)->latest('product'),
            static fn (RecordOutput $product): bool => 'Armchair' === $product->values['name'],
        ))[0];
        $ids = $armchair->values['leaflets'] ?? null;
        self::assertIsArray($ids);
        self::getContainer()->get(RecordManagerInterface::class)->update($armchair->id, new RecordInput('product', ['leaflets' => array_reverse($ids)]));
        $this->client->request('GET', '/o/product', ['sort' => 'name']);
        self::assertSame(['colours.png, care.pdf', '', ''], $this->cells('leaflets'));
    }

    public function testFiltersOnWhetherTheRecordHoldsAFile(): void
    {
        $this->client->request('GET', '/o/product', ['sort' => 'name', 'fedaleForm' => ['manual' => '1']]);
        self::assertSame(['Armchair', 'Bench'], $this->cells('name'));

        $this->client->request('GET', '/o/product', ['sort' => 'name', 'fedaleForm' => ['photo' => '0']]);
        self::assertSame(['Bench', 'Chair'], $this->cells('name'));

        $this->client->request('GET', '/o/product', ['sort' => 'name', 'fedaleForm' => ['leaflets' => '1']]);
        self::assertSame(['Armchair'], $this->cells('name'));
        $this->client->request('GET', '/o/product', ['sort' => 'name', 'fedaleForm' => ['leaflets' => '0']]);
        self::assertSame(['Bench', 'Chair'], $this->cells('name'));
    }

    public function testExportsTheNamesOfTheFiles(): void
    {
        $this->client->request('GET', '/o/product/export', ['format' => 'csv', 'sort' => 'name', 'cols' => 'name,manual,photo,leaflets']);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Name,Manual,Photo,Leaflets', 'Armchair,"armchair <manual>.pdf",armchair.png,"care.pdf, colours.png"', 'Bench,bench.pdf,,', 'Chair,,,'],
            array_map(trim(...), explode("\n", trim((string) $this->client->getInternalResponse()->getContent(), "\u{FEFF}\n"))),
        );
    }

    public function testAGridViewShowsTheFilesButNeverSortsOrJoinsThem(): void
    {
        $manager = self::getContainer()->get(MetadataManagerInterface::class);
        $manager->saveView('product', ViewKind::Grid, 'main', "columns:\n  - name\n  - { field: photo, label: Picture }\nsort: { name: asc }\n");

        $crawler = $this->client->request('GET', '/o/product');
        self::assertResponseIsSuccessful();
        self::assertSame('Picture', trim($crawler->filter('table[data-gv="record_product"] thead th[data-col-key="photo"]')->text()));
        self::assertSame(['armchair.png', '', ''], $this->cells('photo'));
        self::assertCount(1, $crawler->filter('td[data-col-key="photo"] img.md-file-cell__thumbnail'));

        foreach ([
            "columns:\n  - { field: photo, sortable: true }\n" => 'Column #1: "photo" is a file field: it cannot be sorted.',
            "columns:\n  - { field: leaflets, sortable: true }\n" => 'Column #1: "leaflets" is a file field: it cannot be sorted.',
            "columns:\n  - { name: line, template: '{name} {photo}' }\n" => 'Column "line": {photo} is a file field: show it in a column of its own.',
        ] as $source => $error) {
            try {
                $manager->saveView('product', ViewKind::Grid, 'main', $source);
                self::fail('The grid view should have been refused.');
            } catch (MetadataInputInvalid $e) {
                self::assertContains($error, $e->errors['template'] ?? []);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function cells(string $column): array
    {
        return $this->client->getCrawler()
            ->filter(\sprintf('#gv-tbody-record_product td[data-col-key="%s"]', $column))
            ->each(static fn ($cell): string => trim($cell->text()));
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'md-file-');
        file_put_contents($path, $content);

        return $this->paths[] = $path;
    }

    private function png(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(4, 3));

        return $this->file((string) ob_get_clean());
    }
}
