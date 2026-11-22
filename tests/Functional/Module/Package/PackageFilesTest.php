<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Package;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\ExamplePackage;
use App\Tests\Support\SignsIn;
use Fedale\PackageFormat\Archive;
use Fedale\PackageFormat\Document;
use Fedale\PackageFormat\PackageDirectory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Packages as files from the admin: an export downloaded as an archive, an archive uploaded,
 * tried, then installed or given up.
 */
final class PackageFilesTest extends WebTestCase
{
    use SignsIn;
    private KernelBrowser $client;
    private string $directory;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->directory = sys_get_temp_dir().'/md-package-files-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    public function testExportsObjectsAndTheirRecordsAsAnArchive(): void
    {
        self::signInAsSuperAdmin($this->client);
        $manager = self::getContainer()->get(MetadataManagerInterface::class);
        $manager->createObject('category', 'Category', 'Categories');
        $manager->createField('category', 'name', 'text', 'Name', required: true, unique: true);
        $manager->createObject('product', 'Product', 'Products');
        $manager->createField('product', 'name', 'text', 'Name', required: true);
        $manager->createField('product', 'category', 'relation', 'Category', settings: ['target' => 'category']);
        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('category', ['name' => 'Chairs']));

        $crawler = $this->client->request('GET', '/admin/packages');
        $crawler = $this->client->click($crawler->selectLink('Export a package…')->link());
        self::assertResponseIsSuccessful();

        // An object pointing at one not exported: told, nothing downloaded.
        $form = $crawler->selectButton('Download the archive')->form(['export[name]' => 'acme/shop']);
        self::tick($form, 'export[objects]', ['product']);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.md-flash--error', 'category');

        $form = $this->client->getCrawler()->selectButton('Download the archive')->form(['export[name]' => 'acme/shop', 'export[version]' => '1.2.0']);
        self::tick($form, 'export[objects]', ['category', 'product']);
        self::tick($form, 'export[seeds]', ['category']);
        $this->client->submit($form);
        self::assertResponseIsSuccessful();
        $response = $this->client->getInternalResponse();
        self::assertSame('application/gzip', $response->getHeader('Content-Type'));
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=acme-shop-1.2.0.tar.gz');

        // The archive reads as the package exported.
        $archive = $this->directory.'/shop.tar.gz';
        file_put_contents($archive, $this->client->getInternalResponse()->getContent());
        (new Archive())->unpack($archive, $this->directory.'/shop', 10_000_000);
        $package = (new PackageDirectory(new \Fedale\PackageFormat\DocumentCodec()))->read($this->directory.'/shop');
        self::assertSame(['acme/shop', '1.2.0'], [$package->name(), $package->version()]);
        $paths = array_map(static fn (Document $document): string => $document->path, $package->documents);
        self::assertContains('objects/category.yaml', $paths);
        self::assertContains('objects/product.yaml', $paths);
        self::assertContains('seeds/category.yaml', $paths);
    }

    public function testInstallsAnUploadedArchiveAfterATrial(): void
    {
        self::signInAsSuperAdmin($this->client);

        $crawler = $this->upload($this->exampleArchive());
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('#^/admin/packages/uploads/[a-f0-9]{32}$#', (string) parse_url($this->client->getRequest()->getUri(), \PHP_URL_PATH));
        self::assertSelectorTextContains('h1', 'acme/shop 1.0.0');
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('product'));

        $this->client->submit($crawler->selectButton('Install acme/shop 1.0.0')->form());
        self::assertResponseRedirects('/admin/packages', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'Package acme/shop 1.0.0 installed.');
        self::assertTrue(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('product'));
        self::assertSelectorTextContains('body', 'upload: example.tar.gz');
    }

    public function testAnUploadGivenUpIsGone(): void
    {
        self::signInAsSuperAdmin($this->client);

        $crawler = $this->upload($this->exampleArchive());
        $page = (string) parse_url($this->client->getRequest()->getUri(), \PHP_URL_PATH);
        $this->client->submit($crawler->selectButton('Give it up')->form());
        self::assertResponseRedirects('/admin/packages', 303);

        $this->client->request('GET', $page);
        self::assertResponseStatusCodeSame(404);
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('product'));
    }

    public function testRefusesAFileThatIsNoPackage(): void
    {
        self::signInAsSuperAdmin($this->client);
        $path = $this->directory.'/notes.tar.gz';
        file_put_contents($path, 'no archive at all');

        $this->upload($path);
        self::assertSame('/admin/packages', parse_url($this->client->getRequest()->getUri(), \PHP_URL_PATH));
        self::assertSelectorTextContains('.md-flash--error', 'notes.tar.gz');
    }

    public function testOnlyAnAdministratorMayExportOrUpload(): void
    {
        self::signIn($this->client, 'anna@example.com');

        $this->client->request('GET', '/admin/packages/export');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/packages/uploads/'.str_repeat('a', 32));
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param list<string> $values
     */
    private static function tick(\Symfony\Component\DomCrawler\Form $form, string $field, array $values): void
    {
        $boxes = $form[$field];
        foreach (\is_array($boxes) ? $boxes : [$boxes] as $box) {
            if ($box instanceof \Symfony\Component\DomCrawler\Field\ChoiceFormField && [] !== array_intersect(array_map(strval(...), array_filter($box->availableOptionValues(), is_scalar(...))), $values)) {
                $box->tick();
            }
        }
    }

    private function exampleArchive(): string
    {
        $archive = $this->directory.'/example.tar.gz';
        (new Archive())->pack(ExamplePackage::DIRECTORY, $archive);

        return $archive;
    }

    private function upload(string $path): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', '/admin/packages');
        $form = $crawler->selectButton('Upload and try it')->form();
        $file = $form['upload[archive]'];
        self::assertInstanceOf(FileFormField::class, $file);
        $file->upload($path);
        $this->client->submit($form);

        return $this->client->followRedirect();
    }
}
