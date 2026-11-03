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

/**
 * Uploads a file through the record form, as a browser would, then keeps, replaces and
 * removes it.
 */
final class FileFieldControllerTest extends WebTestCase
{
    use SignsIn;

    private const string PDF = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj << /Type /Catalog >> endobj\n";

    private KernelBrowser $client;
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Stateless CSRF protection accepts same-origin requests.
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);
        $container = self::getContainer();
        ($container->get(CreateObjectHandler::class))(new CreateObjectCommand('product', 'Product', 'Products'));
        $createField = $container->get(CreateFieldHandler::class);
        $createField(new CreateFieldCommand('product', 'name', 'text', 'Name', isRequired: true));
        $createField(new CreateFieldCommand('product', 'manual', 'file', 'Manual', settings: ['accept' => 'application/pdf', 'maxSize' => 2]));
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir(\dirname($path))) {
                rmdir(\dirname($path));
            }
        }
        parent::tearDown();
    }

    public function testTheFormOffersAFileInput(): void
    {
        $crawler = $this->client->request('GET', '/o/product/new');

        self::assertResponseIsSuccessful();
        self::assertSame('multipart/form-data', $crawler->filter('form.md-sheet')->attr('enctype'));
        $input = $crawler->filter('input[name="record[manual][upload]"]');
        self::assertSame('file', $input->attr('type'));
        self::assertSame('application/pdf', $input->attr('accept'));
        self::assertSame($input->attr('id'), $crawler->filter('label:contains("Manual")')->attr('for'));
        self::assertSelectorTextContains('[data-gv-field="manual"] .gv-form-help', 'Up to 2 MB.');
        // Nothing to remove yet.
        self::assertCount(0, $crawler->filter('input[name="record[manual][remove]"]'));
    }

    public function testUploadsKeepsAndRemovesTheFile(): void
    {
        $this->client->request('GET', '/o/product/new');
        $this->client->submitForm('Save', ['record[name]' => 'Oslo chair', 'record[manual][upload]' => $this->file(self::PDF, 'oslo-manual.pdf')]);
        self::assertResponseRedirects('/o/product');

        $product = $this->onlyProduct();
        $fileId = $product->values['manual'] ?? null;
        self::assertIsString($fileId);
        self::assertSame('oslo-manual.pdf', self::getContainer()->get(FileStorageInterface::class)->find($fileId)?->originalName);

        // The edit page shows the file; saved without a new one, it keeps it.
        $crawler = $this->client->request('GET', \sprintf('/o/product/%s/edit', $product->id));
        self::assertSelectorTextContains('.md-file__name', 'oslo-manual.pdf');
        self::assertStringContainsString('replace the current one', $crawler->filter('[data-gv-field="manual"]')->text());
        $this->client->submitForm('Save', ['record[name]' => 'Oslo armchair']);
        self::assertResponseRedirects('/o/product');
        self::assertSame($fileId, $this->onlyProduct()->values['manual']);

        $this->client->request('GET', \sprintf('/o/product/%s/edit', $product->id));
        $this->client->submitForm('Save', ['record[manual][remove]' => true]);
        self::assertResponseRedirects('/o/product');
        self::assertNull($this->onlyProduct()->values['manual']);
    }

    public function testAWrongFileIsReportedOnTheField(): void
    {
        $this->client->request('GET', '/o/product/new');
        $this->client->submitForm('Save', ['record[name]' => 'Oslo chair', 'record[manual][upload]' => $this->file('Plain text', 'fake.pdf')]);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('[data-gv-field="manual"] .gv-form-error', 'The mime type of the file is invalid');
        self::assertSame([], self::getContainer()->get(RecordFinderInterface::class)->latest('product'));
    }

    private function onlyProduct(): RecordOutput
    {
        $products = self::getContainer()->get(RecordFinderInterface::class)->latest('product');
        self::assertCount(1, $products);

        return $products[0];
    }

    /**
     * A file with the given name, in a directory of its own: the browser sends the base name.
     */
    private function file(string $content, string $name): string
    {
        $directory = sys_get_temp_dir().'/md-upload-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $path = $directory.'/'.$name;
        file_put_contents($path, $content);
        $this->paths[] = $path;

        return $path;
    }
}
