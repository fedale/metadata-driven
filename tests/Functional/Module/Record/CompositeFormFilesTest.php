<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\File\Contract\FileStorageInterface;
use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * File fields, one file or several, in the nodes of a composite form: the root, a one-to-one
 * section and the rows of a one-to-many section. Their inputs have nested names
 * (composite[sections][parts][0][values][drawing][upload]); the files go with the request, as a
 * browser sends them.
 */
final class CompositeFormFilesTest extends WebTestCase
{
    use SignsIn;
    private const string PDF = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj << /Type /Catalog >> endobj\n";

    private KernelBrowser $client;
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The test storage is in memory: a new kernel per request would lose the stored bytes.
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);

        $container = self::getContainer();
        $object = $container->get(CreateObjectHandler::class);
        $field = $container->get(CreateFieldHandler::class);
        $relation = static fn (string $inverse): array => ['target' => 'product', 'onDelete' => 'cascade', 'inverseName' => $inverse];
        $pdf = ['accept' => 'application/pdf'];

        $object(new CreateObjectCommand('product', 'Product', 'Products'));
        $field(new CreateFieldCommand('product', 'name', 'text', 'Name', isRequired: true));
        $field(new CreateFieldCommand('product', 'manual', 'file', 'Manual', settings: $pdf));

        $object(new CreateObjectCommand('product_sheet', 'Sheet', 'Sheets'));
        $field(new CreateFieldCommand('product_sheet', 'product', 'relation', 'Product', isUnique: true, settings: $relation('sheet')));
        $field(new CreateFieldCommand('product_sheet', 'datasheet', 'file', 'Data sheet', settings: $pdf));
        $field(new CreateFieldCommand('product_sheet', 'photos', 'file', 'Photos', settings: ['image' => true, 'multiple' => true]));

        $object(new CreateObjectCommand('part', 'Part', 'Parts'));
        $field(new CreateFieldCommand('part', 'product', 'relation', 'Product', isRequired: true, settings: $relation('parts')));
        $field(new CreateFieldCommand('part', 'name', 'text', 'Name', isRequired: true));
        $field(new CreateFieldCommand('part', 'drawing', 'file', 'Drawing', settings: $pdf));
        $field(new CreateFieldCommand('part', 'attachments', 'file', 'Attachments', settings: $pdf + ['multiple' => true, 'max' => 2]));

        $forms = $container->get(MetadataManagerInterface::class);
        $forms->saveForm('catalogue', <<<'YAML'
            label: Catalogue entry
            object: product
            fields: [name, manual]
            sections:
              - { name: sheet, label: Sheet, object: product_sheet, via: product }
              - { name: parts, label: Parts, object: part, via: product, mode: many }
            YAML);
        // Files in the rows only, and no row on a new form.
        $forms->saveForm('parts', <<<'YAML'
            label: Parts of a product
            object: product
            fields: [name]
            sections:
              - { name: parts, label: Parts, object: part, via: product, mode: many }
            YAML);
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

    public function testTheFileInputsAreNestedInTheirNodes(): void
    {
        $crawler = $this->client->request('GET', '/f/catalogue/new');

        self::assertResponseIsSuccessful();
        self::assertSame('multipart/form-data', $crawler->filter('#composite-form')->attr('enctype'));
        self::assertSame('file', $crawler->filter('input[name="composite[values][manual][upload]"]')->attr('type'));
        $photos = $crawler->filter('input[name="composite[sections][sheet][values][photos][upload][]"]');
        self::assertSame('multiple', $photos->attr('multiple'));
        self::assertSame('image/*', $photos->attr('accept'));
        self::assertSame('file-list', $crawler->filter('[data-gv-field="photos"] .md-file-list')->attr('data-controller'));
        // A row added with "+" brings its own inputs, from the prototype of the section.
        $prototype = (string) $crawler->filter('[data-gridview-collection-prototype-name-value="__parts__"]')->attr('data-gridview-collection-prototype-value');
        self::assertStringContainsString('name="composite[sections][parts][__parts__][values][drawing][upload]"', $prototype);
        self::assertStringContainsString('name="composite[sections][parts][__parts__][values][attachments][upload][]"', $prototype);

        // The prototype alone makes the form send files.
        $crawler = $this->client->request('GET', '/f/parts/new');
        self::assertCount(0, $crawler->filter('input[type="file"]'));
        self::assertSame('multipart/form-data', $crawler->filter('#composite-form')->attr('enctype'));
    }

    public function testSavesTheFilesOfEveryNodeAndKeepsThemOnEdit(): void
    {
        $crawler = $this->client->request('GET', '/f/catalogue/new');
        $this->submit($crawler, [
            'composite[values][name]' => 'Oslo chair',
            'composite[sections][parts][0][values][name]' => 'Seat',
            'composite[sections][parts][3][values][name]' => 'Leg',
        ], [
            'values' => ['manual' => ['upload' => $this->pdf('assembly.pdf')]],
            'sections' => [
                'sheet' => ['values' => ['datasheet' => ['upload' => $this->pdf('sheet.pdf')], 'photos' => ['upload' => [$this->png('front.png'), $this->png('back.png')]]]],
                'parts' => [0 => ['values' => ['drawing' => ['upload' => $this->pdf('seat.pdf')], 'attachments' => ['upload' => [$this->pdf('fabric.pdf'), $this->pdf('foam.pdf')]]]]],
            ],
        ]);

        $product = $this->only('product');
        self::assertResponseRedirects('/f/catalogue/'.$product->id.'/edit', 303);
        self::assertSame(['assembly.pdf'], $this->names($product, 'manual'));
        $sheet = $this->only('product_sheet');
        self::assertSame(['sheet.pdf'], $this->names($sheet, 'datasheet'));
        self::assertSame(['front.png', 'back.png'], $this->names($sheet, 'photos'));
        $seat = $this->part('Seat');
        self::assertSame(['seat.pdf'], $this->names($seat, 'drawing'));
        self::assertSame(['fabric.pdf', 'foam.pdf'], $this->names($seat, 'attachments'));
        $leg = $this->part('Leg');
        self::assertNull($leg->values['drawing']);
        self::assertSame([], $leg->values['attachments']);

        // The edit page shows the files of each node.
        $crawler = $this->client->request('GET', '/f/catalogue/'.$product->id.'/edit');
        self::assertSame(['front.png', 'back.png'], $crawler->filter('[data-gv-field="photos"] .md-file-list__name')->each(static fn (Crawler $name): string => $name->text()));
        $seatKey = $this->rowKey($crawler, 'Seat');
        self::assertSame(['fabric.pdf', 'foam.pdf'], $this->partField($crawler, $seatKey, 'attachments')->filter('.md-file-list__name')->each(static fn (Crawler $name): string => $name->text()));
        self::assertStringContainsString('seat.pdf', $this->partField($crawler, $seatKey, 'drawing')->text());

        // Saved untouched, every file stays.
        $this->submit($crawler, []);
        self::assertResponseRedirects();
        self::assertSame(['assembly.pdf'], $this->names($this->only('product'), 'manual'));
        self::assertSame(['front.png', 'back.png'], $this->names($this->only('product_sheet'), 'photos'));
        self::assertSame(['seat.pdf'], $this->names($this->part('Seat'), 'drawing'));
        self::assertSame(['fabric.pdf', 'foam.pdf'], $this->names($this->part('Seat'), 'attachments'));

        // The photos reordered, the seat's drawing replaced and its attachments removed.
        [$front, $back] = $this->ids($sheet, 'photos');
        $crawler = $this->client->request('GET', '/f/catalogue/'.$product->id.'/edit');
        $seatKey = $this->rowKey($crawler, 'Seat');
        $this->submit($crawler, [
            'composite[sections][sheet][values][photos][kept]' => $back.','.$front,
            \sprintf('composite[sections][parts][%s][values][attachments][kept]', $seatKey) => '',
        ], ['sections' => ['parts' => [$seatKey => ['values' => ['drawing' => ['upload' => $this->pdf('seat-v2.pdf')]]]]]]);
        self::assertResponseRedirects();
        self::assertSame(['back.png', 'front.png'], $this->names($this->only('product_sheet'), 'photos'));
        self::assertSame(['seat-v2.pdf'], $this->names($this->part('Seat'), 'drawing'));
        self::assertSame([], $this->part('Seat')->values['attachments']);
    }

    public function testARowHoldingOnlyAFileIsNotSkipped(): void
    {
        $crawler = $this->client->request('GET', '/f/catalogue/new');
        $this->submit($crawler, ['composite[values][name]' => 'Oslo chair'], [
            'sections' => ['parts' => [0 => ['values' => ['drawing' => ['upload' => $this->pdf('seat.pdf')]]]]],
        ]);

        self::assertResponseIsUnprocessable();
        self::assertStringContainsString('This value is required.', $this->partField($this->client->getCrawler(), '0', 'name')->text());
        self::assertSame([], self::getContainer()->get(RecordFinderInterface::class)->latest('product'));
    }

    public function testTheErrorsOfAFileGoOnItsNestedInput(): void
    {
        $crawler = $this->client->request('GET', '/f/catalogue/new');
        $this->submit($crawler, [
            'composite[values][name]' => 'Oslo chair',
            'composite[sections][parts][0][values][name]' => 'Seat',
        ], [
            'sections' => [
                'sheet' => ['values' => ['photos' => ['upload' => [$this->png('front.png'), $this->pdf('not-a-photo.png')]]]],
                'parts' => [0 => ['values' => [
                    'drawing' => ['upload' => new UploadedFile($this->file('Plain text'), 'seat.pdf', 'application/pdf', test: true)],
                    'attachments' => ['upload' => [$this->pdf('a.pdf'), $this->pdf('b.pdf'), $this->pdf('c.pdf')]],
                ]]],
            ],
        ]);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('[data-gv-field="photos"]', 'This file is not a valid image.');
        self::assertStringContainsString('The mime type of the file is invalid ("text/plain").', $this->partField($this->client->getCrawler(), '0', 'drawing')->text());
        self::assertStringContainsString('Keep at most 2 files.', $this->partField($this->client->getCrawler(), '0', 'attachments')->text());
        self::assertSame([], self::getContainer()->get(RecordFinderInterface::class)->latest('product'));
    }

    public function testARowCannotTakeTheFileOfAnotherRow(): void
    {
        $crawler = $this->client->request('GET', '/f/catalogue/new');
        $this->submit($crawler, [
            'composite[values][name]' => 'Oslo chair',
            'composite[sections][parts][0][values][name]' => 'Seat',
            'composite[sections][parts][1][values][name]' => 'Leg',
        ], ['sections' => ['parts' => [
            0 => ['values' => ['drawing' => ['upload' => $this->pdf('seat.pdf')], 'attachments' => ['upload' => [$this->pdf('fabric.pdf')]]]],
            1 => ['values' => ['drawing' => ['upload' => $this->pdf('leg.pdf')]]],
        ]]]);
        $product = $this->only('product');

        // The rows posted with each other's ids: each keeps the files on the page, which are
        // those of the other record.
        $crawler = $this->client->request('GET', '/f/catalogue/'.$product->id.'/edit');
        $seatKey = $this->rowKey($crawler, 'Seat');
        $legKey = $this->rowKey($crawler, 'Leg');
        $this->submit($crawler, [
            \sprintf('composite[sections][parts][%s][id]', $seatKey) => $this->part('Leg')->id,
            \sprintf('composite[sections][parts][%s][id]', $legKey) => $this->part('Seat')->id,
        ]);

        self::assertResponseIsUnprocessable();
        self::assertStringContainsString('This file is not the file of this record.', $this->partField($this->client->getCrawler(), $seatKey, 'drawing')->text());
        self::assertStringContainsString('One of the files is not a file of this record.', $this->partField($this->client->getCrawler(), $seatKey, 'attachments')->text());
        self::assertSame(['seat.pdf'], $this->names($this->part('Seat'), 'drawing'));
        self::assertSame(['leg.pdf'], $this->names($this->part('Leg'), 'drawing'));
    }

    /**
     * Submits the form of the page with its values changed, and the files at their nested
     * place: getPhpValues() leaves the file inputs out.
     *
     * @param array<string, string> $values
     * @param array<string, mixed>  $files  under "composite"
     */
    private function submit(Crawler $crawler, array $values, array $files = []): void
    {
        $form = $crawler->selectButton('Save')->form();
        $added = [];
        foreach ($values as $name => $value) {
            if ($form->has($name)) {
                $form->setValues([$name => $value]);
                continue;
            }
            // A row added with "+" has no input on the page yet.
            parse_str($name.'='.rawurlencode($value), $input);
            $added = array_replace_recursive($added, $input);
        }
        $phpValues = array_replace_recursive($form->getPhpValues(), $added);
        $this->client->request($form->getMethod(), $form->getUri(), $phpValues, [] === $files ? [] : ['composite' => $files]);
    }

    /**
     * The key of the row of a part on the page, found by its name.
     */
    private function rowKey(Crawler $crawler, string $name): string
    {
        $input = $crawler->filter(\sprintf('input[name^="composite[sections][parts]"][name$="[values][name]"][value="%s"]', $name));
        self::assertCount(1, $input);
        if (1 !== preg_match('/\[parts\]\[(\d+)\]/', (string) $input->attr('name'), $match)) {
            self::fail(\sprintf('No row key in the name of the part "%s".', $name));
        }

        return $match[1];
    }

    /**
     * A field of a row of parts: the rows have no id of their own, their inputs have.
     */
    private function partField(Crawler $crawler, string $key, string $field): Crawler
    {
        $row = $crawler->filter(\sprintf('[name="composite[sections][parts][%s][values][name]"]', $key))->closest('.md-row');
        self::assertNotNull($row);
        $found = $row->filter(\sprintf('[data-gv-field="%s"]', $field));
        self::assertCount(1, $found);

        return $found;
    }

    private function only(string $object): RecordOutput
    {
        $records = self::getContainer()->get(RecordFinderInterface::class)->latest($object);
        self::assertCount(1, $records);

        return $records[0];
    }

    private function part(string $name): RecordOutput
    {
        $parts = array_values(array_filter(self::getContainer()->get(RecordFinderInterface::class)->latest('part'), static fn (RecordOutput $part): bool => $name === $part->values['name']));
        self::assertCount(1, $parts);

        return $parts[0];
    }

    /**
     * @return list<string> the files a field of the record holds, one or several
     */
    private function ids(RecordOutput $record, string $field): array
    {
        return array_values(array_filter((array) ($record->values[$field] ?? null), \is_string(...)));
    }

    /**
     * @return list<string>
     */
    private function names(RecordOutput $record, string $field): array
    {
        $files = self::getContainer()->get(FileStorageInterface::class);

        return array_map(static fn (string $id): string => (string) $files->find($id)?->originalName, $this->ids($record, $field));
    }

    private function pdf(string $name): UploadedFile
    {
        return new UploadedFile($this->file(self::PDF.$name), $name, 'application/pdf', test: true);
    }

    private function png(string $name): UploadedFile
    {
        ob_start();
        imagepng(imagecreatetruecolor(4, 3));

        return new UploadedFile($this->file((string) ob_get_clean()), $name, 'image/png', test: true);
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'md-file-');
        file_put_contents($path, $content);

        return $this->paths[] = $path;
    }
}
