<?php

declare(strict_types=1);

namespace App\Tests\Unit\TemplateFormat;

use App\TemplateFormat\IndexEntry;
use App\TemplateFormat\InvalidIndex;
use App\TemplateFormat\TemplateIndex;
use PHPUnit\Framework\TestCase;

final class TemplateIndexTest extends TestCase
{
    private const string ADDRESS = 'https://templates.acme.example/v1/index.json';

    public function testResolvesArchivesAgainstTheIndexAndPicksTheNewestUsableVersion(): void
    {
        $sha = str_repeat('a', 64);
        $index = TemplateIndex::fromJson((string) json_encode(['format' => TemplateIndex::FORMAT, 'templates' => ['acme/shop' => [
            '1.0.0' => ['url' => 'acme-shop-1.0.0.tar.gz', 'sha256' => $sha],
            '1.10.0' => ['url' => 'https://cdn.example/acme-shop-1.10.0.tar.gz', 'sha256' => $sha],
            '1.2.0' => ['url' => 'acme-shop-1.2.0.tar.gz', 'sha256' => $sha],
            '2.0.0' => ['url' => 'acme-shop-2.0.0.tar.gz', 'sha256' => $sha, 'yanked' => true],
            '3.0.0' => ['url' => 'acme-shop-3.0.0.tar.gz', 'sha256' => $sha, 'requires' => ['format' => 'metadata-driven/v2']],
        ]]]), self::ADDRESS);

        self::assertSame('https://templates.acme.example/v1/acme-shop-1.0.0.tar.gz', $index->find('acme/shop', '1.0.0')?->url);
        self::assertSame(['1.0.0', '1.2.0', '1.10.0', '2.0.0', '3.0.0'], array_map(static fn (IndexEntry $entry): string => $entry->version, $index->versions('acme/shop')));
        // Not the yanked 2.0.0, not 3.0.0 of another format: 1.10.0, compared as versions.
        self::assertSame('1.10.0', $index->latest('acme/shop')?->version);
        self::assertNull($index->latest('acme/blog'));
    }

    public function testRefusesWhatIsNotAnIndex(): void
    {
        foreach ([
            '{"templates": {}}' => 'is not of format metadata-driven/index/v1',
            '{"format": "metadata-driven/index/v1", "templates": {"Shop": {}}}' => 'lists "Shop", which is no template name',
            '{"format": "metadata-driven/index/v1", "templates": {"acme/shop": {"1.0.0": {"url": "a.tar.gz", "sha256": "abc"}}}}' => 'it needs a url and a sha256',
            'not json' => 'is not JSON',
        ] as $json => $message) {
            try {
                TemplateIndex::fromJson($json, self::ADDRESS);
                self::fail($message);
            } catch (InvalidIndex $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testWritesItselfBackWithRelativeAddresses(): void
    {
        $entry = new IndexEntry('acme/shop', '1.0.0', 'https://templates.acme.example/v1/acme-shop-1.0.0.tar.gz', str_repeat('b', 64));

        $json = TemplateIndex::empty()->with($entry)->toJson(self::ADDRESS);

        self::assertStringContainsString('"url": "acme-shop-1.0.0.tar.gz"', $json);
        self::assertEquals($entry, TemplateIndex::fromJson($json, self::ADDRESS)->find('acme/shop', '1.0.0'));
    }
}
