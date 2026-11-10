<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Template;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Template\Application\TemplateInstaller;
use App\Module\Template\Domain\Repository\TemplateInstallationRepository;
use App\Tests\Support\ExampleTemplate;
use App\Tests\Support\FakeTemplateServer;
use App\Tests\Support\PublishesPlacesAndPeople;
use App\Tests\Support\RemovesDirectories;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A template named from an index comes with the templates it requires that are missing:
 * acme/people (requires acme/places ^1.0), and acme/places in 1.2.0, 1.3.0 and 2.0.0, served by
 * the fake template server.
 */
final class TemplateRequirementChainTest extends KernelTestCase
{
    use PublishesPlacesAndPeople;
    use RemovesDirectories;

    private const string INDEX = 'https://templates.acme.example/index.json';

    private string $directory;
    private Application $application;

    protected function setUp(): void
    {
        FakeTemplateServer::reset();
        $this->directory = sys_get_temp_dir().'/md-chain-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->application = new Application(self::bootKernel());
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
        parent::tearDown();
    }

    public function testInstallsTheMissingRequirementFirstInTheNewestVersionItAllows(): void
    {
        $this->publishPlacesAndPeople();
        $this->addIndex();

        $output = $this->command('metadata:template:install', ['template' => 'acme/people']);

        self::assertStringContainsString('acme/people requires templates this instance does not have: they are installed first, acme/places 1.3.0.', $output);
        self::assertStringContainsString('Templates acme/places 1.3.0, acme/people 1.0.0 installed.', $output);
        $installations = self::getContainer()->get(TemplateInstallationRepository::class);
        self::assertSame('1.3.0', $installations->findByName('acme/places')?->getVersion());
        self::assertSame(self::INDEX, $installations->findByName('acme/places')->getSource());
        self::assertSame('region', self::getContainer()->get(MetadataRegistryInterface::class)->getObject('person')->field('region')->relation()?->target);
        // Nothing downloaded is left behind.
        self::assertSame([], glob(sys_get_temp_dir().'/md-template-*') ?: []);
    }

    public function testADryRunGoesThroughTheWholeChainAndChangesNothing(): void
    {
        $this->publishPlacesAndPeople();
        $this->addIndex();

        $output = $this->command('metadata:template:install', ['template' => 'acme/people', '--dry-run' => true]);

        self::assertStringContainsString('Templates acme/places 1.3.0, acme/people 1.0.0 can be installed. Dry run: nothing was changed.', $output);
        self::assertSame([], self::getContainer()->get(TemplateInstallationRepository::class)->all());
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('region'));
    }

    public function testLeavesARequirementInstalledAlreadyAsItIs(): void
    {
        $this->publishPlacesAndPeople();
        $this->addIndex();
        self::getContainer()->get(TemplateInstaller::class)->install(ExampleTemplate::places(), ExampleTemplate::PLACES);

        $output = $this->command('metadata:template:install', ['template' => 'acme/people']);

        self::assertStringContainsString('Template acme/people 1.0.0 installed.', $output);
        // Never upgraded on the side, though 1.3.0 is offered.
        self::assertSame('1.2.0', self::getContainer()->get(TemplateInstallationRepository::class)->findByName('acme/places')?->getVersion());
    }

    public function testRefusesARequirementInstalledInAVersionItDoesNotAllow(): void
    {
        $this->publishPlacesAndPeople();
        $this->addIndex();
        $this->command('metadata:template:install', ['template' => 'acme/places@2.0.0']);

        $output = $this->command('metadata:template:install', ['template' => 'acme/people'], 1);

        self::assertStringContainsString('acme/places 2.0.0 does not fit ^1.0.', $output);
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('person'));
    }

    public function testARefusedRequirementLeavesNothingInstalled(): void
    {
        $this->publishPlacesAndPeople();
        $this->addIndex();
        self::getContainer()->get(MetadataManagerInterface::class)->createObject('province', 'Province', 'Provinces');

        $output = $this->command('metadata:template:install', ['template' => 'acme/people'], 1);

        self::assertStringContainsString('acme/places 1.3.0: objects/province.yaml: spec.name: This instance already has an object "province".', $output);
        self::assertSame([], self::getContainer()->get(TemplateInstallationRepository::class)->all());
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('region'));
    }

    public function testRefusesARequirementNoIndexOffersInAFittingVersion(): void
    {
        $this->publishFixture('people', '1.0.0');
        $this->serveFixtures();
        $this->addIndex();
        self::assertStringContainsString('No index offers acme/places, which is required in version ^1.0.', $this->command('metadata:template:install', ['template' => 'acme/people'], 1));

        $this->publishFixture('places', '2.0.0');
        $this->publishFixture('places', '1.2.0', yanked: true);
        $this->serveFixtures();
        self::assertStringContainsString('acme offers no version of acme/places that fits ^1.0 and is not yanked.', $this->command('metadata:template:install', ['template' => 'acme/people'], 1));
        self::assertSame([], glob(sys_get_temp_dir().'/md-template-*') ?: []);
    }

    public function testRefusesTemplatesRequiringEachOther(): void
    {
        $this->publishFixture('people', '1.0.0');
        $this->publishFixture('places', '1.2.0', requires: ['acme/people' => '^1.0']);
        $this->serveFixtures();
        $this->addIndex();

        $output = $this->command('metadata:template:install', ['template' => 'acme/people'], 1);

        self::assertStringContainsString('These templates require each other: acme/people requires acme/places requires acme/people.', $output);
        self::assertSame([], glob(sys_get_temp_dir().'/md-template-*') ?: []);
    }

    public function testADirectoryComesAloneAndNamesWhatIsMissing(): void
    {
        $this->publishPlacesAndPeople();
        $this->addIndex();

        $output = $this->command('metadata:template:install', ['template' => ExampleTemplate::PEOPLE], 1);

        self::assertStringContainsString('acme/places is required: it must be installed', $output);
        // No archive was downloaded: the index was read only when it was added.
        self::assertSame([], array_values(array_filter(FakeTemplateServer::requested(), static fn (string $url): bool => str_ends_with($url, '.tar.gz'))));
    }

    private function addIndex(): void
    {
        $this->command('metadata:template:repo', ['action' => 'add', 'name' => 'acme', 'url' => self::INDEX, '--unsigned' => true]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function command(string $name, array $input, int $status = 0): string
    {
        $command = new CommandTester($this->application->find($name));
        self::assertSame($status, $command->execute($input), $command->getDisplay());

        // The console wraps long messages, a note repeating its "!" on every line: one line,
        // single spaces.
        return (string) preg_replace(['/\s+/', '/ ! (?!\[)/'], [' ', ' '], $command->getDisplay());
    }
}
