<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Template;

use App\Module\FieldType\Contract\Dto\SettingKind;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use Fedale\TemplateFormat\FieldTypes\FieldTypeCatalog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The format describes the field types on its own (field-types.v1.json), so that it can check a
 * template without the app. This keeps that description true: a type or a setting added to the
 * app and not to the format fails here.
 */
final class FieldTypeCatalogMatchesTheAppTest extends KernelTestCase
{
    public function testTheFormatKnowsEveryFieldTypeOfTheApp(): void
    {
        $app = self::getContainer()->get(FieldTypeRegistryInterface::class)->all();

        self::assertEqualsCanonicalizing(array_keys($app), (new FieldTypeCatalog())->names());
    }

    public function testTheFormatDescribesEverySettingAsTheAppDoes(): void
    {
        $catalog = new FieldTypeCatalog();
        foreach (self::getContainer()->get(FieldTypeRegistryInterface::class)->all() as $name => $type) {
            $described = $catalog->settings($name);
            self::assertSame(array_keys($type->defaultSettings()), array_keys($described), \sprintf('The settings of "%s".', $name));
            self::assertSame($type->defaultSettings(), $catalog->defaults($name), \sprintf('The defaults of "%s".', $name));

            foreach ($type->settingDefinitions() as $definition) {
                $setting = $described[$definition->key];
                $where = \sprintf('Setting "%s" of "%s".', $definition->key, $name);
                self::assertContains($setting['kind'], match ($definition->kind) {
                    SettingKind::Integer => ['integer'],
                    SettingKind::Boolean => ['boolean'],
                    // A string setting may name a field: the format then holds its id.
                    SettingKind::String => ['string', 'field'],
                    SettingKind::Choice => ['choice'],
                    SettingKind::ObjectName => ['object'],
                    SettingKind::OptionList => ['options'],
                }, $where);
                if (SettingKind::Choice === $definition->kind) {
                    self::assertIsArray($definition->options['choices'] ?? null, $where);
                    self::assertSame(array_keys($definition->options['choices']), $setting['choices'], $where);
                }
                foreach (['min', 'max'] as $bound) {
                    self::assertSame($definition->options[$bound] ?? null, $setting[$bound] ?? null, $where.' '.$bound);
                }
            }
        }
    }
}
