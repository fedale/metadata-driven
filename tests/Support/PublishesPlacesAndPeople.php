<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Fedale\TemplateFormat\Archive;
use Fedale\TemplateFormat\Document;
use Fedale\TemplateFormat\DocumentCodec;
use Fedale\TemplateFormat\PackageDirectory;
use Fedale\TemplateFormat\TemplateIndex;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Publishes versions of acme/places and acme/people (ExampleTemplate::places(), people()) on
 * the fake template server, packed, and an index listing them at self::INDEX. The test
 * provides $this->directory, a temporary directory, and the INDEX constant (PublishesTemplates
 * has one).
 */
trait PublishesPlacesAndPeople
{
    /** @var array<string, array<string, array<string, mixed>>> name => version => entry */
    private array $entries = [];

    /**
     * acme/places 1.2.0, 1.3.0 and 2.0.0, acme/people 1.0.0 (requiring acme/places ^1.0).
     */
    private function publishPlacesAndPeople(): void
    {
        $this->publishFixture('places', '1.2.0');
        $this->publishFixture('places', '1.3.0');
        $this->publishFixture('places', '2.0.0');
        $this->publishFixture('people', '1.0.0');
        $this->serveFixtures();
    }

    /**
     * Packs a fixture (places or people) as a version, with other requirements if given, and
     * lists it in the index served next.
     *
     * @param array<string, string>|null $requires the templates it requires; null as the fixture says
     * @param string|null                $name     another name for it; null for the fixture's
     */
    private function publishFixture(string $fixture, string $version, bool $yanked = false, ?array $requires = null, ?string $name = null): void
    {
        $source = 'places' === $fixture ? ExampleTemplate::PLACES : ExampleTemplate::PEOPLE;
        $copy = \sprintf('%s/%s-%s', $this->directory, str_replace('/', '-', $name ?? $fixture), $version);
        (new Filesystem())->mirror($source, $copy);
        $codec = new DocumentCodec();
        $manifest = (new PackageDirectory($codec))->read($copy)->manifest;
        $spec = ['version' => $version] + $manifest->spec;
        if (null !== $requires) {
            $spec['requires'] = ['format' => 'metadata-driven/v1'] + $requires;
        }
        $name ??= $manifest->id;
        file_put_contents($copy.'/template.yaml', $codec->encode(new Document($manifest->kind, $name, $spec, $manifest->path)));
        $archive = $copy.'.tar.gz';
        (new Archive())->pack($copy, $archive);

        $file = \sprintf('%s-%s.tar.gz', str_replace('/', '-', $name), $version);
        FakeTemplateServer::serve('https://templates.acme.example/'.$file, (string) file_get_contents($archive));
        $this->entries[$name][$version] = ['url' => $file, 'sha256' => (string) hash_file('sha256', $archive), 'yanked' => $yanked];
    }

    private function serveFixtures(): void
    {
        FakeTemplateServer::serve(self::INDEX, (string) json_encode(['format' => TemplateIndex::FORMAT, 'templates' => $this->entries]));
    }
}
