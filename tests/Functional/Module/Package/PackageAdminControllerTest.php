<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Package;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Package\Application\PackageInstaller;
use App\Module\Package\Application\Source\PackageSources;
use App\Module\Package\Domain\Repository\PackageInstallationRepository;
use App\Tests\Support\ExamplePackage;
use App\Tests\Support\FakePackageServer;
use App\Tests\Support\PublishesPackages;
use App\Tests\Support\PublishesPlacesAndPeople;
use App\Tests\Support\RemovesDirectories;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The packages in the admin, through HTTP, as a browser would: the indexes served by the fake
 * package server.
 */
final class PackageAdminControllerTest extends WebTestCase
{
    use PublishesPlacesAndPeople;
    use PublishesPackages;
    use RemovesDirectories;
    use SignsIn;

    private KernelBrowser $client;
    private string $directory;

    protected function setUp(): void
    {
        FakePackageServer::reset();
        $this->directory = sys_get_temp_dir().'/md-admin-packages-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
        parent::tearDown();
    }

    public function testAddsAnIndexAndListsWhatItOffers(): void
    {
        $this->publish(['1.0.0' => $this->archive('1.0.0')]);

        $this->client->request('GET', '/admin/packages');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Add the index', ['index[name]' => 'acme', 'index[url]' => self::INDEX, 'index[unsigned]' => true]);
        self::assertResponseRedirects('/admin/packages');

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'Index "acme" added.');
        self::assertSame('/admin/packages/install?package=acme/shop@1.0.0&repo=acme', $crawler->filter('a:contains("Install…")')->attr('href'));
        self::assertAnySelectorTextContains('table', 'No: accepted unsigned');
    }

    public function testRefusesAnIndexWithoutKeyOrConsent(): void
    {
        $this->publish(['1.0.0' => $this->archive('1.0.0')]);

        $this->client->request('GET', '/admin/packages');
        $this->client->submitForm('Add the index', ['index[name]' => 'acme', 'index[url]' => self::INDEX]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.gv-form-errors', 'accept it unsigned (--unsigned)');
    }

    public function testPreviewsAnInstallThenInstalls(): void
    {
        $this->publish(['1.0.0' => $this->archive('1.0.0')]);
        self::getContainer()->get(PackageSources::class)->add('acme', self::INDEX, unsigned: true);

        $crawler = $this->client->request('GET', '/admin/packages/install?package=acme/shop@1.0.0&repo=acme');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.md-facts', 'category, product, tag');
        // The preview is a trial: nothing is installed yet.
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('product'));

        $this->client->submit($crawler->selectButton('Install acme/shop 1.0.0')->form());
        self::assertResponseRedirects('/admin/packages');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'Package acme/shop 1.0.0 installed.');
        self::assertSame('1.0.0', self::getContainer()->get(PackageInstallationRepository::class)->findByName('acme/shop')?->getVersion());
    }

    public function testPreviewsAndInstallsAPackageWithTheRequirementsItComesWith(): void
    {
        $this->publishPlacesAndPeople();
        self::getContainer()->get(PackageSources::class)->add('acme', self::INDEX, unsigned: true);

        $crawler = $this->client->request('GET', '/admin/packages/install?package=acme/people&repo=acme');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'acme/people requires packages this instance does not have: they are installed with it, first.');
        self::assertSame(['acme/places 1.3.0', 'acme/people 1.0.0'], $crawler->filter('h2')->each(static fn ($heading): string => $heading->text()));
        // Every package of the chain says which index it comes from.
        self::assertSelectorTextContains('main', 'From '.self::INDEX);
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('region'));

        $this->client->submit($crawler->selectButton('Install acme/people 1.0.0 and what it requires')->form());
        self::assertResponseRedirects('/admin/packages');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'Packages acme/places 1.3.0, acme/people 1.0.0 installed.');
        self::assertSame('1.3.0', self::getContainer()->get(PackageInstallationRepository::class)->findByName('acme/places')?->getVersion());
    }

    public function testResolvesTheConflictsOfAnUpgradeOneByOne(): void
    {
        $this->publish(['1.0.0' => $this->archive('1.0.0'), '1.1.0' => $this->archive('1.1.0', tagLabel: 'Label')]);
        self::getContainer()->get(PackageSources::class)->add('acme', self::INDEX, unsigned: true);
        self::getContainer()->get(PackageInstaller::class)->install(ExamplePackage::package(), self::INDEX);
        self::getContainer()->get(MetadataManagerInterface::class)->relabelObject('tag', 'Keyword', 'Keywords');

        $crawler = $this->client->request('GET', '/admin/packages/upgrade?package=acme/shop@1.1.0&repo=acme');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('legend', 'objects/tag.yaml: change object acme.tag');
        self::assertSelectorTextContains('.md-columns', 'label: Keyword');
        self::assertSelectorTextContains('.md-columns', 'label: Label');

        $this->client->submit($crawler->selectButton('Upgrade to 1.1.0')->form(['upgrade[c0]' => 'overwrite']));
        self::assertResponseRedirects('/admin/packages');
        self::assertSame('Label', self::getContainer()->get(MetadataRegistryInterface::class)->getObject('tag')->labelSingular);
    }

    public function testRemovesAnIndex(): void
    {
        $this->publish(['1.0.0' => $this->archive('1.0.0')]);
        self::getContainer()->get(PackageSources::class)->add('acme', self::INDEX, unsigned: true);

        $this->client->request('GET', '/admin/packages');
        $this->client->submitForm('Remove');

        self::assertResponseRedirects('/admin/packages');
        self::assertSame([], self::getContainer()->get(PackageSources::class)->indexes());
    }

    public function testNamesPackagesNeverPaths(): void
    {
        $this->client->request('GET', '/admin/packages/install?package=/var/www/packages/shop');

        self::assertResponseStatusCodeSame(404);
    }

    public function testIsForAdministratorsOnly(): void
    {
        self::signIn($this->client, 'reader@example.com');

        $this->client->request('GET', '/admin/packages');

        self::assertResponseStatusCodeSame(403);
    }
}
