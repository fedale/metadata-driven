<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Package;

use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Package\Application\Source\PackageSources;
use App\Module\Package\Domain\Exception\PackageSourceFailed;
use App\Module\Package\Domain\Repository\PackageInstallationRepository;
use App\Module\Package\Domain\Repository\PackageSourceRepository;
use App\Shared\Workspace\CurrentWorkspace;
use App\Tests\Support\ExamplePackage;
use App\Tests\Support\FakePackageServer;
use App\Tests\Support\PublishesPackages;
use App\Tests\Support\RemovesDirectories;
use Fedale\PackageFormat\Archive;
use Fedale\PackageFormat\RepositoryIndex;
use Fedale\PackageFormat\Signature;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Packages downloaded from indexes, served by a fake web server: the example package packed
 * as version 1.0.0, and a 1.1.0 relabelling its tags.
 */
final class PackageSourcesTest extends KernelTestCase
{
    use PublishesPackages;
    use RemovesDirectories;

    private string $directory;
    private Application $application;

    protected function setUp(): void
    {
        FakePackageServer::reset();
        $this->directory = sys_get_temp_dir().'/md-sources-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->application = new Application(self::bootKernel());
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
        parent::tearDown();
    }

    public function testInstallsAndUpgradesAPackageByItsName(): void
    {
        $this->publish(['1.0.0' => $this->archive('1.0.0'), '1.1.0' => $this->archive('1.1.0', tagLabel: 'Label')]);
        $this->command('metadata:package:repo', ['action' => 'add', 'name' => 'acme', 'url' => self::INDEX, '--unsigned' => true]);

        $install = $this->command('metadata:package:install', ['package' => 'acme/shop@1.0.0']);
        self::assertStringContainsString('Package acme/shop 1.0.0 installed.', $install);
        $installation = self::getContainer()->get(PackageInstallationRepository::class)->findByName('acme/shop');
        self::assertSame(self::INDEX, $installation?->getSource());

        self::assertMatchesRegularExpression('~acme/shop 1\.1\.0 acme 1\.0\.0 \(upgrade available\)~', $this->command('metadata:package:search', ['term' => 'shop']));

        // The newest version, from the index it was installed from.
        self::assertStringContainsString('acme/shop upgraded from 1.0.0 to 1.1.0.', $this->command('metadata:package:upgrade', ['package' => 'acme/shop']));
        self::assertSame('Label', self::getContainer()->get(MetadataRegistryInterface::class)->getObject('tag')->labelSingular);
        // Nothing downloaded is left behind.
        self::assertSame([], glob(sys_get_temp_dir().'/md-package-*') ?: []);
    }

    public function testRefusesAnArchiveThatIsNotTheOneListed(): void
    {
        $this->publish(['1.0.0' => $this->archive('1.0.0')], sha256: str_repeat('0', 64));
        $this->command('metadata:package:repo', ['action' => 'add', 'name' => 'acme', 'url' => self::INDEX, '--unsigned' => true]);

        $output = $this->command('metadata:package:install', ['package' => 'acme/shop'], 1);

        self::assertStringContainsString('its checksum differs. Nothing was unpacked.', $output);
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('product'));
    }

    public function testFetchesOverHttpsOnly(): void
    {
        $output = $this->command('metadata:package:repo', ['action' => 'add', 'name' => 'acme', 'url' => 'http://packages.acme.example/index.json', '--unsigned' => true], 1);

        self::assertStringContainsString('is not an https address: packages are fetched over https only.', $output);
        self::assertSame([], FakePackageServer::requested());
    }

    public function testAskWhichIndexWhenTwoOfferAPackage(): void
    {
        $this->publish(['1.0.0' => $this->archive('1.0.0')]);
        $mirror = 'https://mirror.example/index.json';
        FakePackageServer::serve($mirror, (string) file_get_contents($this->directory.'/index.json'));
        $this->command('metadata:package:repo', ['action' => 'add', 'name' => 'acme', 'url' => self::INDEX, '--unsigned' => true]);
        $this->command('metadata:package:repo', ['action' => 'add', 'name' => 'mirror', 'url' => $mirror, '--unsigned' => true]);

        self::assertStringContainsString('acme/shop is offered by acme and mirror: choose one with --repo.', $this->command('metadata:package:install', ['package' => 'acme/shop'], 1));
        self::assertStringContainsString('installed.', $this->command('metadata:package:install', ['package' => 'acme/shop', '--repo' => 'acme']));
    }

    public function testAYankedVersionIsInstalledOnlyWhenNamed(): void
    {
        $this->publish(['1.0.0' => $this->archive('1.0.0'), '1.1.0' => $this->archive('1.1.0')], yanked: ['1.1.0']);
        $this->command('metadata:package:repo', ['action' => 'add', 'name' => 'acme', 'url' => self::INDEX, '--unsigned' => true]);

        $this->command('metadata:package:install', ['package' => 'acme/shop']);

        self::assertSame('1.0.0', self::getContainer()->get(PackageInstallationRepository::class)->findByName('acme/shop')?->getVersion());
    }

    public function testInstallsFromASignedIndex(): void
    {
        $keys = Signature::keyPair();
        $this->publish(['1.0.0' => $this->archive('1.0.0')], secretKey: $keys['secret']);

        $this->command('metadata:package:repo', ['action' => 'add', 'name' => 'acme', 'url' => self::INDEX, '--key' => $keys['public']]);

        self::assertStringContainsString('installed.', $this->command('metadata:package:install', ['package' => 'acme/shop']));
        self::assertStringContainsString('acme '.self::INDEX.' yes', $this->command('metadata:package:repo', []));
    }

    public function testRefusesWhatTheIndexKeyDidNotSign(): void
    {
        $keys = Signature::keyPair();
        $this->publish(['1.0.0' => $this->archive('1.0.0')], secretKey: $keys['secret']);

        // Added with another key: refused at once.
        $this->command('metadata:package:repo', ['action' => 'add', 'name' => 'acme', 'url' => self::INDEX, '--key' => Signature::keyPair()['public']], 1);

        // Added with its key, then the index replaced by one signed with another: refused at install.
        $this->command('metadata:package:repo', ['action' => 'add', 'name' => 'acme', 'url' => self::INDEX, '--key' => $keys['public']]);
        $this->publish(['1.0.0' => $this->archive('1.0.0', tagLabel: 'Label')], secretKey: Signature::keyPair()['secret']);
        self::assertStringContainsString('acme/shop 1.0.0 is not signed with the key of acme: it was not downloaded.', $this->command('metadata:package:install', ['package' => 'acme/shop'], 1));
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('product'));
    }

    public function testAnIndexWithoutKeyNeedsConsent(): void
    {
        $this->publish(['1.0.0' => $this->archive('1.0.0')]);

        self::assertStringContainsString('or accept it unsigned (--unsigned), trusting its checksums only.', $this->command('metadata:package:repo', ['action' => 'add', 'name' => 'acme', 'url' => self::INDEX], 1));
        self::assertSame([], FakePackageServer::requested());
    }

    public function testMakesKeysAndSignsWhatItPacks(): void
    {
        $output = $this->command('metadata:package:keygen', ['file' => $this->directory.'/acme.key']);
        self::assertSame(0o600, fileperms($this->directory.'/acme.key') & 0o777);
        self::assertSame(1, preg_match('~Public key ([A-Za-z0-9+/=]{44})~', $output, $match), $output);
        $publicKey = $match[1] ?? '';

        $this->command('metadata:package:pack', ['directory' => ExamplePackage::DIRECTORY, 'archive' => $this->directory.'/acme-shop-1.0.0.tar.gz', '--index' => $this->directory.'/index.json', '--sign' => $this->directory.'/acme.key']);

        $entry = RepositoryIndex::fromJson((string) file_get_contents($this->directory.'/index.json'), self::INDEX)->find('acme/shop', '1.0.0');
        self::assertNotNull($entry);
        self::assertTrue(Signature::verify($entry, $publicKey));
        self::assertStringContainsString('a key is never overwritten', $this->command('metadata:package:keygen', ['file' => $this->directory.'/acme.key'], 1));
    }

    public function testRefusesAddressesOfThePrivateNetwork(): void
    {
        // As configured outside the tests: private networks refused.
        $sources = new PackageSources(
            self::getContainer()->get(PackageSourceRepository::class),
            self::getContainer()->get(CurrentWorkspace::class),
            new MockHttpClient(new MockResponse('{}')),
            new Archive(),
            null,
            null,
            50,
            200,
            allowPrivateNetworks: false,
        );

        foreach (['https://127.0.0.1/index.json', 'https://169.254.169.254/latest/meta-data'] as $address) {
            try {
                $sources->index($address);
                self::fail(\sprintf('%s must be refused.', $address));
            } catch (PackageSourceFailed $e) {
                self::assertStringContainsString('is blocked', $e->getMessage());
            }
        }
    }

    public function testYanksASignedVersion(): void
    {
        $keys = Signature::keyPair();
        file_put_contents($this->directory.'/acme.key', $keys['secret']);
        $this->command('metadata:package:pack', ['directory' => ExamplePackage::DIRECTORY, 'archive' => $this->directory.'/acme-shop-1.0.0.tar.gz', '--index' => $this->directory.'/index.json', '--sign' => $this->directory.'/acme.key']);

        // The signature covers the flag: no key, no yank.
        self::assertStringContainsString('give the secret key (--sign)', $this->command('metadata:package:yank', ['index' => $this->directory.'/index.json', 'name' => 'acme/shop', 'version' => '1.0.0'], 1));
        $this->command('metadata:package:yank', ['index' => $this->directory.'/index.json', 'name' => 'acme/shop', 'version' => '1.0.0', '--sign' => $this->directory.'/acme.key']);

        $entry = RepositoryIndex::fromJson((string) file_get_contents($this->directory.'/index.json'), self::INDEX)->find('acme/shop', '1.0.0');
        self::assertTrue($entry?->yanked);
        self::assertTrue(Signature::verify($entry, $keys['public']));
    }

    public function testPacksAPackageIntoAnIndex(): void
    {
        $this->command('metadata:package:pack', ['directory' => ExamplePackage::DIRECTORY, 'archive' => $this->directory.'/acme-shop-1.0.0.tar.gz', '--index' => $this->directory.'/index.json']);

        $index = RepositoryIndex::fromJson((string) file_get_contents($this->directory.'/index.json'), self::INDEX);
        $entry = $index->find('acme/shop', '1.0.0');
        self::assertSame('https://packages.acme.example/acme-shop-1.0.0.tar.gz', $entry?->url);
        self::assertSame(hash_file('sha256', $this->directory.'/acme-shop-1.0.0.tar.gz'), $entry->sha256);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function command(string $name, array $input, int $status = 0): string
    {
        $command = new CommandTester($this->application->find($name));
        self::assertSame($status, $command->execute($input), $command->getDisplay());

        // The console wraps long messages: one line, single spaces.
        return (string) preg_replace('/\s+/', ' ', $command->getDisplay());
    }
}
