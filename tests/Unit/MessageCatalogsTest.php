<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The texts of the application come in every language with the same keys: a key missing in
 * one shows its English text there, and one left over is a text nobody reads.
 */
final class MessageCatalogsTest extends TestCase
{
    public function testEveryLanguageHasTheKeysOfTheEnglishCatalog(): void
    {
        $english = self::keys(__DIR__.'/../../translations/messages.en.yaml');
        $files = glob(__DIR__.'/../../translations/messages.*.yaml') ?: [];
        self::assertGreaterThan(1, \count($files));

        foreach ($files as $file) {
            $keys = self::keys($file);
            self::assertSame([], array_values(array_diff($english, $keys)), basename($file).' misses these keys.');
            self::assertSame([], array_values(array_diff($keys, $english)), basename($file).' has keys the English catalog does not.');
        }
    }

    /**
     * @return list<string> the keys of a catalog, flattened with dots as the translator does
     */
    private static function keys(string $file): array
    {
        $catalog = Yaml::parseFile($file);
        self::assertIsArray($catalog);

        return self::flatten($catalog);
    }

    /**
     * @param array<mixed> $messages
     *
     * @return list<string>
     */
    private static function flatten(array $messages, string $prefix = ''): array
    {
        $keys = [];
        foreach ($messages as $key => $value) {
            $keys = [...$keys, ...(\is_array($value) ? self::flatten($value, $prefix.$key.'.') : [$prefix.$key])];
        }

        return $keys;
    }
}
