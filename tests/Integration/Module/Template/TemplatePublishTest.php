<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Template;

use App\Tests\Support\ExampleTemplate;
use App\Tests\Support\RemovesDirectories;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * metadata:template:publish: a template version copied into a checkout of the catalog, after
 * the checks the catalog's pull request check would run.
 */
final class TemplatePublishTest extends KernelTestCase
{
    use RemovesDirectories;

    private string $directory;
    private string $catalog;
    private Application $application;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/md-publish-'.bin2hex(random_bytes(4));
        $this->catalog = $this->directory.'/catalog';
        mkdir($this->catalog.'/templates', 0o777, true);
        file_put_contents($this->catalog.'/vendors.yaml', "acme:\n  owners: [mario-rossi]\n");
        $this->application = new Application(self::bootKernel());
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
        parent::tearDown();
    }

    public function testCopiesTheVersionIntoTheCatalog(): void
    {
        $source = $this->version('1.0.0');
        file_put_contents($source.'/README.md', "# Shop\n");

        $output = $this->command(['directory' => $source]);

        $target = $this->catalog.'/templates/acme/shop/1.0.0';
        self::assertStringContainsString('Copied into '.$this->catalog.'/templates/acme/shop/1.0.0.', $output);
        self::assertStringContainsString('git checkout -b add_acme_shop_1_0_0 main', $output);
        self::assertStringContainsString('git commit -m "Add acme/shop 1.0.0"', $output);
        foreach (['template.yaml', 'objects/product.yaml', 'seeds/products.csv', 'files/products/oslo.png', 'README.md'] as $file) {
            self::assertFileEquals($source.'/'.$file, $target.'/'.$file);
        }
    }

    public function testADryRunCopiesNothing(): void
    {
        $output = $this->command(['directory' => $this->version('1.0.0'), '--dry-run' => true]);

        self::assertStringContainsString('The catalog would take it. Dry run: nothing was copied.', $output);
        self::assertDirectoryDoesNotExist($this->catalog.'/templates/acme');
    }

    public function testTheNumberMustFitTheChangesAgainstThePublishedVersion(): void
    {
        $this->command(['directory' => $this->version('1.0.0')]);

        self::assertStringContainsString(
            'acme/shop 1.0.0 is already in the catalog: the new version must be higher.',
            $this->command(['directory' => $this->version('1.0.0', 'again')], 1),
        );

        $colour = "\n    - id: acme.tag.colour\n      name: colour\n      type: text\n      label: Colour\n";
        $patch = $this->version('1.0.1');
        file_put_contents($patch.'/objects/tag.yaml', $colour, \FILE_APPEND);
        self::assertStringContainsString(
            'objects/tag.yaml: field acme.tag.colour: an addition needs a new minor version, 1.0.1 is not one.',
            $this->command(['directory' => $patch], 1),
        );

        $minor = $this->version('1.1.0');
        file_put_contents($minor.'/objects/tag.yaml', $colour, \FILE_APPEND);
        $output = $this->command(['directory' => $minor]);
        self::assertMatchesRegularExpression('~Compared with\s+1\.0\.0~', $output);
        self::assertDirectoryExists($this->catalog.'/templates/acme/shop/1.1.0');
    }

    public function testRefusesWhatTheCatalogWouldRefuse(): void
    {
        $source = $this->version('1.0.0');

        file_put_contents($this->catalog.'/vendors.yaml', "globex:\n  owners: [anna]\n");
        self::assertStringContainsString('The vendor "acme" is not registered in the catalog (vendors.yaml)', $this->command(['directory' => $source], 1));

        file_put_contents($this->catalog.'/vendors.yaml', "acme:\n  owners: [mario-rossi]\n");
        file_put_contents($this->catalog.'/yanked.yaml', "acme/shop: all\n");
        self::assertStringContainsString('acme/shop is removed from the catalog (yanked.yaml: all)', $this->command(['directory' => $source], 1));

        unlink($this->catalog.'/yanked.yaml');
        symlink('/etc/hostname', $source.'/files/hostname');
        self::assertStringContainsString('files/hostname: A symbolic link: the catalog takes files and directories only.', $this->command(['directory' => $source], 1));

        self::assertStringContainsString('is not a checkout of the template catalog', $this->command(['directory' => $source, '--catalog' => $this->directory], 1));
        self::assertDirectoryDoesNotExist($this->catalog.'/templates/acme');
    }

    public function testPublishesATemplateRequiringOneTheCatalogHas(): void
    {
        self::copyOf(ExampleTemplate::PLACES, $this->catalog.'/templates/acme/places/1.2.0');

        // Checked against acme/places 1.2.0: its relations and cascade name the region and province.
        $this->command(['directory' => ExampleTemplate::PEOPLE]);

        self::assertFileExists($this->catalog.'/templates/acme/people/1.0.0/objects/person.yaml');
    }

    public function testRefusesARequirementTheCatalogDoesNotHave(): void
    {
        $refused = 'template.yaml: spec.requires.acme/places: The catalog has no version of acme/places that fits ^1.0 and is not yanked: publish it first, in a pull request of its own.';

        $output = $this->command(['directory' => ExampleTemplate::PEOPLE], 1);
        self::assertStringContainsString($refused, $output);
        // Said once, not as the checker says it to an installation.
        self::assertStringNotContainsString('must be installed', $output);

        self::copyOf(ExampleTemplate::PLACES, $this->catalog.'/templates/acme/places/1.2.0');
        file_put_contents($this->catalog.'/yanked.yaml', "acme/places: [1.2.0]\n");
        self::assertStringContainsString($refused, $this->command(['directory' => ExampleTemplate::PEOPLE], 1));
        self::assertDirectoryDoesNotExist($this->catalog.'/templates/acme/people');
    }

    public function testRefusesTemplatesRequiringEachOther(): void
    {
        self::copyOf(ExampleTemplate::PEOPLE, $this->catalog.'/templates/acme/people/1.0.0');
        $places = $this->directory.'/places-1.3.0';
        self::copyOf(ExampleTemplate::PLACES, $places);
        $manifest = (string) file_get_contents($places.'/template.yaml');
        file_put_contents($places.'/template.yaml', str_replace(['version: 1.2.0', "format: metadata-driven/v1\n  resources"], ['version: 1.3.0', "format: metadata-driven/v1\n    acme/people: ^1.0\n  resources"], $manifest));

        self::assertStringContainsString('These templates would require each other: acme/places requires acme/people requires acme/places.', $this->command(['directory' => $places], 1));
    }

    /**
     * A copy of the example template as version $version.
     */
    private function version(string $version, string $suffix = ''): string
    {
        $target = $this->directory.'/source-'.$version.$suffix;
        self::copyOf(ExampleTemplate::DIRECTORY, $target);
        $manifest = $target.'/template.yaml';
        file_put_contents($manifest, str_replace('version: 1.0.0', 'version: '.$version, (string) file_get_contents($manifest)));

        return $target;
    }

    private static function copyOf(string $from, string $to): void
    {
        mkdir($to, 0o777, true);
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $target = $to.'/'.substr($entry->getPathname(), \strlen($from) + 1);
                $entry->isDir() ? mkdir($target) : copy($entry->getPathname(), $target);
            }
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function command(array $input, int $status = 0): string
    {
        $command = new CommandTester($this->application->find('metadata:template:publish'));
        self::assertSame($status, $command->execute($input + ['--catalog' => $this->catalog]), $command->getDisplay());

        // The console wraps long messages: one line, single spaces.
        return (string) preg_replace('/\s+/', ' ', $command->getDisplay());
    }
}
