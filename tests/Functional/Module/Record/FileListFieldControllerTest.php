<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\File\Contract\FileStorageInterface;
use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Uploads several files through the record form, as a browser would, then keeps, reorders and
 * removes them through the hidden list the file-list controller fills in.
 */
final class FileListFieldControllerTest extends WebTestCase
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
        // Stateless CSRF protection accepts same-origin requests.
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);
        $container = self::getContainer();
        ($container->get(CreateObjectHandler::class))(new CreateObjectCommand('product', 'Product', 'Products'));
        $createField = $container->get(CreateFieldHandler::class);
        $createField(new CreateFieldCommand('product', 'name', 'text', 'Name', isRequired: true));
        $createField(new CreateFieldCommand('product', 'manuals', 'file', 'Manuals', settings: ['accept' => 'application/pdf', 'maxSize' => 2, 'multiple' => true, 'max' => 4]));
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

    public function testTheFormOffersOneInputForSeveralFiles(): void
    {
        $crawler = $this->client->request('GET', '/o/product/new');

        self::assertResponseIsSuccessful();
        $input = $crawler->filter('input[name="record[manuals][upload][]"]');
        self::assertSame('file', $input->attr('type'));
        self::assertSame('multiple', $input->attr('multiple'));
        self::assertSame('application/pdf', $input->attr('accept'));
        self::assertSame($input->attr('id'), $crawler->filter('label:contains("Manuals")')->attr('for'));
        self::assertSame('file-list', $crawler->filter('[data-gv-field="manuals"] .md-file-list')->attr('data-controller'));
        self::assertSelectorTextContains('[data-gv-field="manuals"] .gv-form-help', 'Up to 4 files, 2 MB each');
        self::assertCount(0, $crawler->filter('.md-file-list__item'));
    }

    public function testUploadsReordersAndRemovesFiles(): void
    {
        $this->submit($this->client->request('GET', '/o/product/new'), ['record[name]' => 'Oslo chair'], [$this->pdf('assembly.pdf'), $this->pdf('care.pdf')]);
        self::assertResponseRedirects('/o/product');
        $product = $this->onlyProduct();
        self::assertSame(['assembly.pdf', 'care.pdf'], $this->names($product));
        [$assembly, $care] = $this->ids($product);

        // The edit page lists the files in order, with the buttons the controller shows.
        $crawler = $this->client->request('GET', \sprintf('/o/product/%s/edit', $product->id));
        self::assertSame(['assembly.pdf', 'care.pdf'], $crawler->filter('.md-file-list__name')->each(static fn (Crawler $name): string => $name->text()));
        self::assertSame('/files/'.$assembly, $crawler->filter('.md-file-list__name')->attr('href'));
        self::assertSame($assembly.','.$care, $crawler->filter('input[name="record[manuals][kept]"]')->attr('value'));
        self::assertSame('Move care.pdf up', $crawler->filter('.md-file-list__item')->eq(1)->filter('[data-action="file-list#up"]')->attr('aria-label'));
        self::assertSame('', $crawler->filter('.md-file-list__actions')->attr('hidden'));

        // Saved untouched, the list stays.
        $this->submit($crawler, ['record[name]' => 'Oslo armchair']);
        self::assertResponseRedirects('/o/product');
        self::assertSame([$assembly, $care], $this->ids($this->onlyProduct()));

        // Care first, assembly removed, a warranty added at the end.
        $crawler = $this->client->request('GET', \sprintf('/o/product/%s/edit', $product->id));
        $this->submit($crawler, ['record[manuals][kept]' => $care], [$this->pdf('warranty.pdf')]);
        self::assertResponseRedirects('/o/product');
        self::assertSame(['care.pdf', 'warranty.pdf'], $this->names($this->onlyProduct()));

        // Everything removed.
        $crawler = $this->client->request('GET', \sprintf('/o/product/%s/edit', $product->id));
        $this->submit($crawler, ['record[manuals][kept]' => '']);
        self::assertResponseRedirects('/o/product');
        self::assertSame([], $this->onlyProduct()->values['manuals']);
    }

    public function testAnIdNotListedOnThePageIsIgnored(): void
    {
        $this->submit($this->client->request('GET', '/o/product/new'), ['record[name]' => 'Oslo chair'], [$this->pdf('assembly.pdf')]);
        $product = $this->onlyProduct();
        $stranger = self::getContainer()->get(FileStorageInterface::class)->store($this->file(self::PDF.'stranger'), 'stranger.pdf');

        $crawler = $this->client->request('GET', \sprintf('/o/product/%s/edit', $product->id));
        $this->submit($crawler, ['record[manuals][kept]' => $stranger->id.','.$this->ids($product)[0]]);
        self::assertResponseRedirects('/o/product');
        self::assertSame(['assembly.pdf'], $this->names($this->onlyProduct()));
    }

    public function testTooManyFilesAreReportedOnTheField(): void
    {
        $files = array_map(fn (int $index): UploadedFile => $this->pdf(\sprintf('manual-%d.pdf', $index)), range(1, 5));
        $this->submit($this->client->request('GET', '/o/product/new'), ['record[name]' => 'Oslo chair'], $files);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('[data-gv-field="manuals"] .gv-form-error', 'Keep at most 4 files.');
        self::assertSame([], self::getContainer()->get(RecordFinderInterface::class)->latest('product'));
    }

    /**
     * Submits the record form with several files in its one multiple input: the DomCrawler
     * form fills a file input with one file only, so the files go with the request.
     *
     * @param array<string, string> $values
     * @param list<UploadedFile>    $uploads
     */
    private function submit(Crawler $crawler, array $values, array $uploads = []): void
    {
        $form = $crawler->selectButton('Save')->form()->setValues($values);
        // getPhpValues() leaves the file inputs out.
        $this->client->request($form->getMethod(), $form->getUri(), $form->getPhpValues(), ['record' => ['manuals' => ['upload' => $uploads]]]);
    }

    private function onlyProduct(): RecordOutput
    {
        $products = self::getContainer()->get(RecordFinderInterface::class)->latest('product');
        self::assertCount(1, $products);

        return $products[0];
    }

    /**
     * @return list<string>
     */
    private function ids(RecordOutput $product): array
    {
        $ids = $product->values['manuals'] ?? null;
        self::assertIsArray($ids);

        return array_values(array_filter($ids, \is_string(...)));
    }

    /**
     * @return list<string>
     */
    private function names(RecordOutput $product): array
    {
        $files = self::getContainer()->get(FileStorageInterface::class);

        return array_map(static fn (string $id): string => (string) $files->find($id)?->originalName, $this->ids($product));
    }

    private function pdf(string $name): UploadedFile
    {
        return new UploadedFile($this->file(self::PDF.$name), $name, 'application/pdf', test: true);
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'md-file-');
        file_put_contents($path, $content);

        return $this->paths[] = $path;
    }
}
