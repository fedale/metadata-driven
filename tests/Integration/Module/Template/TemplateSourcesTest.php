<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Template;

use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Template\Domain\Repository\TemplateInstallationRepository;
use App\TemplateFormat\Archive;
use App\TemplateFormat\TemplateIndex;
use App\Tests\Support\FakeTemplateServer;
use App\Tests\Support\RemovesDirectories;
use App\Tests\Unit\TemplateFormat\PackageCheckerTest;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Templates downloaded from indexes, served by a fake web server: the example template packed
 * as version 1.0.0, and a 1.1.0 relabelling its tags.
 */
final class TemplateSourcesTest extends KernelTestCase
{
    use RemovesDirectories;

    private const string INDEX = 'https://templates.acme.example/index.json';

    private string $directory;
    private Application $application;

    protected function setUp(): void
    {
        FakeTemplateServer::reset();
        $this->directory = sys_get_temp_dir().'/md-sources-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->application = new Application(self::bootKernel());
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
        parent::tearDown();
    }

    public function testInstallsAndUpgradesATemplateByItsName(): void
    {
        $this->publish(['1.0.0' => $this->archive('1.0.0'), '1.1.0' => $this->archive('1.1.0', tagLabel: 'Label')]);
        $this->command('metadata:template:repo', ['action' => 'add', 'name' => 'acme', 'url' => self::INDEX]);

        $install = $this->command('metadata:template:install', ['template' => 'acme/shop@1.0.0']);
        self::assertStringContainsString('Template acme/shop 1.0.0 installed.', $install);
        $installation = self::getContainer()->get(TemplateInstallationRepository::class)->findByName('acme/shop');
        self::assertSame(self::INDEX, $installation?->getSource());

        self::assertMatchesRegularExpression('~acme/shop 1\.1\.0 acme 1\.0\.0 \(upgrade available\)~', $this->command('metadata:template:search', ['term' => 'shop']));

        // The newest version, from the index it was installed from.
        self::assertStringContainsString('acme/shop upgraded from 1.0.0 to 1.1.0.', $this->command('metadata:template:upgrade', ['template' => 'acme/shop']));
        self::assertSame('Label', self::getContainer()->get(MetadataRegistryInterface::class)->getObject('tag')->labelSingular);
        // Nothing downloaded is left behind.
        self::assertSame([], glob(sys_get_temp_dir().'/md-template-*') ?: []);
    }

    public function testRefusesAnArchiveThatIsNotTheOneListed(): void
    {
        $this->publish(['1.0.0' => $this->archive('1.0.0')], sha256: str_repeat('0', 64));
        $this->command('metadata:template:repo', ['action' => 'add', 'name' => 'acme', 'url' => self::INDEX]);

        $output = $this->command('metadata:template:install', ['template' => 'acme/shop'], 1);

        self::assertStringContainsString('its checksum differs. Nothing was unpacked.', $output);
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('product'));
    }

    public function testFetchesOverHttpsOnly(): void
    {
        $output = $this->command('metadata:template:repo', ['action' => 'add', 'name' => 'acme', 'url' => 'http://templates.acme.example/index.json'], 1);

        self::assertStringContainsString('is not an https address: templates are fetched over https only.', $output);
        self::assertSame([], FakeTemplateServer::requested());
    }

    public function testAskWhichIndexWhenTwoOfferATemplate(): void
    {
        $this->publish(['1.0.0' => $this->archive('1.0.0')]);
        $mirror = 'https://mirror.example/index.json';
        FakeTemplateServer::serve($mirror, (string) file_get_contents($this->directory.'/index.json'));
        $this->command('metadata:template:repo', ['action' => 'add', 'name' => 'acme', 'url' => self::INDEX]);
        $this->command('metadata:template:repo', ['action' => 'add', 'name' => 'mirror', 'url' => $mirror]);

        self::assertStringContainsString('acme/shop is offered by acme and mirror: choose one with --repo.', $this->command('metadata:template:install', ['template' => 'acme/shop'], 1));
        self::assertStringContainsString('installed.', $this->command('metadata:template:install', ['template' => 'acme/shop', '--repo' => 'acme']));
    }

    public function testAYankedVersionIsInstalledOnlyWhenNamed(): void
    {
        $this->publish(['1.0.0' => $this->archive('1.0.0'), '1.1.0' => $this->archive('1.1.0')], yanked: ['1.1.0']);
        $this->command('metadata:template:repo', ['action' => 'add', 'name' => 'acme', 'url' => self::INDEX]);

        $this->command('metadata:template:install', ['template' => 'acme/shop']);

        self::assertSame('1.0.0', self::getContainer()->get(TemplateInstallationRepository::class)->findByName('acme/shop')?->getVersion());
    }

    public function testPacksATemplateIntoAnIndex(): void
    {
        $this->command('metadata:template:pack', ['directory' => PackageCheckerTest::FIXTURE, 'archive' => $this->directory.'/acme-shop-1.0.0.tar.gz', '--index' => $this->directory.'/index.json']);

        $index = TemplateIndex::fromJson((string) file_get_contents($this->directory.'/index.json'), self::INDEX);
        $entry = $index->find('acme/shop', '1.0.0');
        self::assertSame('https://templates.acme.example/acme-shop-1.0.0.tar.gz', $entry?->url);
        self::assertSame(hash_file('sha256', $this->directory.'/acme-shop-1.0.0.tar.gz'), $entry->sha256);
    }

    /**
     * The example template packed as a version, written in the test's directory.
     */
    private function archive(string $version, ?string $tagLabel = null): string
    {
        $copy = $this->directory.'/shop-'.$version;
        (new \Symfony\Component\Filesystem\Filesystem())->mirror(PackageCheckerTest::FIXTURE, $copy);
        file_put_contents($copy.'/template.yaml', str_replace('version: 1.0.0', 'version: '.$version, (string) file_get_contents($copy.'/template.yaml')));
        if (null !== $tagLabel) {
            file_put_contents($copy.'/objects/tag.yaml', str_replace('label: Tag', 'label: '.$tagLabel, (string) file_get_contents($copy.'/objects/tag.yaml')));
        }
        $archive = $copy.'.tar.gz';
        (new Archive())->pack($copy, $archive);

        return $archive;
    }

    /**
     * Serves the archives and an index listing them.
     *
     * @param array<string, string> $archives version => archive
     * @param list<string>          $yanked
     */
    private function publish(array $archives, ?string $sha256 = null, array $yanked = []): void
    {
        $templates = [];
        foreach ($archives as $version => $archive) {
            $file = \sprintf('acme-shop-%s.tar.gz', $version);
            FakeTemplateServer::serve('https://templates.acme.example/'.$file, (string) file_get_contents($archive));
            $templates['acme/shop'][$version] = ['url' => $file, 'sha256' => $sha256 ?? hash_file('sha256', $archive), 'yanked' => \in_array($version, $yanked, true)];
        }
        $index = (string) json_encode(['format' => TemplateIndex::FORMAT, 'templates' => $templates]);
        file_put_contents($this->directory.'/index.json', $index);
        FakeTemplateServer::serve(self::INDEX, $index);
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
