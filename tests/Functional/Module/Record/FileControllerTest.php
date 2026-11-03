<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\RecordAction;
use App\Module\FieldType\Contract\Dto\FileUpload;
use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\SignsIn;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Downloads the files of the records and their thumbnails, as a browser would.
 */
final class FileControllerTest extends WebTestCase
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
        $container = self::getContainer();
        ($container->get(CreateObjectHandler::class))(new CreateObjectCommand('product', 'Product', 'Products'));
        ($container->get(CreateObjectHandler::class))(new CreateObjectCommand('supplier', 'Supplier', 'Suppliers'));
        $createField = $container->get(CreateFieldHandler::class);
        $createField(new CreateFieldCommand('product', 'name', 'text', 'Name', isRequired: true));
        $createField(new CreateFieldCommand('product', 'manual', 'file', 'Manual'));
        $createField(new CreateFieldCommand('product', 'photo', 'file', 'Photo', settings: ['image' => true]));
        $createField(new CreateFieldCommand('supplier', 'name', 'text', 'Name'));
        self::signInAsSuperAdmin($this->client);
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

    public function testShowsAnImageAndDownloadsADocument(): void
    {
        $product = $this->product();

        $this->client->request('GET', '/files/'.$this->fileOf($product, 'photo'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertResponseHeaderSame('Content-Disposition', 'inline; filename=oslo.png');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertStringContainsString('sandbox', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('private', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame((string) file_get_contents($this->paths[1]), $this->client->getInternalResponse()->getContent());

        $this->client->request('GET', '/files/'.$this->fileOf($product, 'manual'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        // Not an image: downloaded, never shown in the app's pages. The UTF-8 name is kept.
        self::assertResponseHeaderSame('Content-Disposition', "attachment; filename=\"Manuale d_uso _.pdf\"; filename*=utf-8''Manuale%20d%E2%80%99uso%20%C3%A8.pdf");
        self::assertSame(self::PDF, $this->client->getInternalResponse()->getContent());
    }

    public function testAnUnchangedFileIsNotSentAgain(): void
    {
        $id = $this->fileOf($this->product(), 'manual');
        $this->client->request('GET', '/files/'.$id);
        $etag = (string) $this->client->getResponse()->headers->get('ETag');

        $this->client->request('GET', '/files/'.$id, server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
    }

    public function testScalesAnImageDownOnce(): void
    {
        $id = $this->fileOf($this->product(), 'photo');

        $this->client->request('GET', \sprintf('/files/%s/thumbnail/medium', $id));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        $size = getimagesizefromstring((string) $this->client->getResponse()->getContent());
        self::assertSame([240, 160], [$size[0] ?? null, $size[1] ?? null]);
        /** @var FilesystemOperator $storage */
        $storage = self::getContainer()->get('record_files.storage');
        self::assertCount(1, $storage->listContents('thumbnails/medium')->toArray());

        // A document has no thumbnail.
        $this->client->request('GET', \sprintf('/files/%s/thumbnail/small', $this->fileOf($this->product(), 'manual')));
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyWhoMayReadTheRecordGetsItsFile(): void
    {
        $id = $this->fileOf($this->product(), 'manual');

        self::signIn($this->client, 'buyer@example.com', [Permissions::record('supplier', RecordAction::Read)]);
        $this->client->request('GET', '/files/'.$id);
        self::assertResponseStatusCodeSame(404);

        self::signIn($this->client, 'reader@example.com', [Permissions::record('product', RecordAction::Read)]);
        $this->client->request('GET', '/files/'.$id);
        self::assertResponseIsSuccessful();
    }

    public function testAFileOfADeletedRecordOrOfNoRecordIsNotFound(): void
    {
        $product = $this->product();
        $id = $this->fileOf($product, 'manual');
        self::getContainer()->get(RecordManagerInterface::class)->delete('product', $product->id);

        $this->client->request('GET', '/files/'.$id);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/files/0193a5b2-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheEditFormLinksTheFileAndPreviewsTheImage(): void
    {
        $product = $this->product();

        $crawler = $this->client->request('GET', \sprintf('/o/product/%s/edit', $product->id));
        self::assertSame('/files/'.$this->fileOf($product, 'manual'), $crawler->filter('[data-gv-field="manual"] a.md-file__name')->attr('href'));
        self::assertSame(\sprintf('/files/%s/thumbnail/medium', $this->fileOf($product, 'photo')), $crawler->filter('[data-gv-field="photo"] img.md-file__preview')->attr('src'));
        self::assertCount(0, $crawler->filter('[data-gv-field="manual"] img'));
    }

    private function product(): RecordOutput
    {
        return self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('product', [
            'name' => 'Oslo chair',
            'manual' => new FileUpload($this->file(self::PDF), 'Manuale d’uso è.pdf'),
            'photo' => new FileUpload($this->png(600, 400), 'oslo.png'),
        ]));
    }

    private function fileOf(RecordOutput $record, string $field): string
    {
        $id = $record->values[$field] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'md-file-');
        file_put_contents($path, $content);

        return $this->paths[] = $path;
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function png(int $width, int $height): string
    {
        ob_start();
        imagepng(imagecreatetruecolor($width, $height));

        return $this->file((string) ob_get_clean());
    }
}
