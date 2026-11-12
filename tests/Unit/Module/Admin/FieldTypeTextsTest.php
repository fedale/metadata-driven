<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Admin;

use App\Module\Admin\UI\FieldTypeTexts;
use App\Module\FieldType\Contract\Dto\SettingDefinition;
use App\Module\FieldType\Domain\Type\BooleanFieldType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class FieldTypeTextsTest extends TestCase
{
    public function testTranslatesTheTextsTheCatalogHas(): void
    {
        $type = new BooleanFieldType();
        $display = self::setting($type, 'display');
        $texts = new FieldTypeTexts(self::translator('it'));

        self::assertSame('Sì / No', $texts->label($type));
        self::assertSame('Aspetto', $texts->settingLabel($type, $display));
        self::assertSame('Interruttore', $texts->settingChoice($type, $display, 'switch', 'On/off switch'));
    }

    public function testFallsBackOnTheTextsTheFieldTypeDeclares(): void
    {
        $type = new BooleanFieldType();
        $display = self::setting($type, 'display');

        // A key the catalog lacks, in Italian.
        $italian = new FieldTypeTexts(self::translator('it'));
        self::assertSame($type->description(), $italian->description($type));
        self::assertSame('Checkbox', $italian->settingChoice($type, $display, 'checkbox', 'Checkbox'));
        self::assertNull($italian->settingHelp($type, $display));

        // English has no catalog at all: the declared texts, never a key.
        $english = new FieldTypeTexts(self::translator('en'));
        self::assertSame('Yes / No', $english->label($type));
        self::assertSame('Display', $english->settingLabel($type, $display));
    }

    private static function translator(string $locale): Translator
    {
        $translator = new Translator($locale);
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'boolean' => ['label' => 'Sì / No', 'settings' => ['display' => ['label' => 'Aspetto', 'choices' => ['switch' => 'Interruttore']]]],
        ], 'it', FieldTypeTexts::DOMAIN);

        return $translator;
    }

    private static function setting(BooleanFieldType $type, string $key): SettingDefinition
    {
        foreach ($type->settingDefinitions() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }
        self::fail(\sprintf('No setting "%s".', $key));
    }
}
