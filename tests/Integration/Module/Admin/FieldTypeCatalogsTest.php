<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Admin;

use App\Module\Admin\UI\FieldTypeTexts;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every language but English translates every text of every field type, and nothing else: a
 * new type or setting needs its keys in translations/field_types.<locale>.yaml, a removed one
 * leaves none behind. English is what the field types declare (see FieldTypeTexts).
 */
final class FieldTypeCatalogsTest extends KernelTestCase
{
    public function testTheCatalogsHaveTheKeysOfTheFieldTypes(): void
    {
        $expected = [];
        foreach (self::getContainer()->get(FieldTypeRegistryInterface::class)->all() as $type) {
            $expected[] = $type->name().'.label';
            $expected[] = $type->name().'.description';
            foreach ($type->settingDefinitions() as $setting) {
                $prefix = $type->name().'.settings.'.$setting->key;
                $expected[] = $prefix.'.label';
                if (null !== $setting->help) {
                    $expected[] = $prefix.'.help';
                }
                foreach (array_keys(\is_array($setting->options['choices'] ?? null) ? $setting->options['choices'] : []) as $value) {
                    $expected[] = $prefix.'.choices.'.$value;
                }
            }
        }

        $locales = array_diff(self::getContainer()->getParameter('kernel.enabled_locales'), [self::getContainer()->getParameter('kernel.default_locale')]);
        self::assertNotEmpty($locales);
        foreach ($locales as $locale) {
            $file = \sprintf('%s/translations/%s.%s.yaml', self::getContainer()->getParameter('kernel.project_dir'), FieldTypeTexts::DOMAIN, $locale);
            self::assertFileExists($file);
            $catalog = Yaml::parseFile($file);
            self::assertIsArray($catalog);
            $keys = self::flatten($catalog);
            self::assertSame([], array_values(array_diff($expected, $keys)), basename($file).' misses these keys.');
            self::assertSame([], array_values(array_diff($keys, $expected)), basename($file).' has keys no field type has.');
        }
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
