<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Fedale\TemplateFormat\Archive;
use Fedale\TemplateFormat\IndexEntry;
use Fedale\TemplateFormat\Signature;
use Fedale\TemplateFormat\TemplateIndex;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Publishes versions of the example template on the fake template server: packed archives and
 * an index listing them, at INDEX. The test provides $this->directory, a temporary directory.
 */
trait PublishesTemplates
{
    private const string INDEX = 'https://templates.acme.example/index.json';

    /**
     * The example template packed as a version, written in the test's directory.
     */
    private function archive(string $version, ?string $tagLabel = null): string
    {
        $copy = $this->directory.'/shop-'.$version;
        (new Filesystem())->mirror(ExampleTemplate::DIRECTORY, $copy);
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
     * @param array<string, string> $archives  version => archive
     * @param list<string>          $yanked
     * @param string|null           $secretKey the key to sign every entry with
     */
    private function publish(array $archives, ?string $sha256 = null, array $yanked = [], ?string $secretKey = null): void
    {
        $templates = [];
        foreach ($archives as $version => $archive) {
            $file = \sprintf('acme-shop-%s.tar.gz', $version);
            FakeTemplateServer::serve('https://templates.acme.example/'.$file, (string) file_get_contents($archive));
            $entry = ['url' => $file, 'sha256' => $sha256 ?? (string) hash_file('sha256', $archive), 'yanked' => \in_array($version, $yanked, true)];
            if (null !== $secretKey) {
                $entry['signature'] = Signature::sign(new IndexEntry('acme/shop', $version, $file, $entry['sha256']), $secretKey);
            }
            $templates['acme/shop'][$version] = $entry;
        }
        $index = (string) json_encode(['format' => TemplateIndex::FORMAT, 'templates' => $templates]);
        file_put_contents($this->directory.'/index.json', $index);
        FakeTemplateServer::serve(self::INDEX, $index);
    }
}
