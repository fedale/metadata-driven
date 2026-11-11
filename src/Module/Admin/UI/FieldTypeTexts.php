<?php

declare(strict_types=1);

namespace App\Module\Admin\UI;

use App\Module\FieldType\Contract\Dto\SettingDefinition;
use App\Module\FieldType\Contract\FieldTypeInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\TranslatorBagInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The texts of a field type in the language of the page: its name, its description and its
 * settings, from translations/field_types.<locale>.yaml.
 *
 * A field type declares its texts in English, and those are the fallback: there is no English
 * catalog to keep in step, and a type added later shows its own texts until it is translated.
 * Hence the lookup in the catalog rather than trans(), which would answer the key itself.
 */
final class FieldTypeTexts
{
    public const string DOMAIN = 'field_types';

    public function __construct(
        // The interface has no alias of its own; the translator service implements it.
        #[Autowire(service: 'translator')]
        private readonly TranslatorBagInterface $translator,
    ) {
    }

    #[AsTwigFunction('field_type_label')]
    public function label(FieldTypeInterface $type): string
    {
        return $this->text($type->name().'.label', $type->label());
    }

    #[AsTwigFunction('field_type_description')]
    public function description(FieldTypeInterface $type): string
    {
        return $this->text($type->name().'.description', $type->description());
    }

    public function settingLabel(FieldTypeInterface $type, SettingDefinition $setting): string
    {
        return $this->text(self::settingKey($type, $setting).'.label', $setting->label);
    }

    public function settingHelp(FieldTypeInterface $type, SettingDefinition $setting): ?string
    {
        return null === $setting->help ? null : $this->text(self::settingKey($type, $setting).'.help', $setting->help);
    }

    public function settingChoice(FieldTypeInterface $type, SettingDefinition $setting, string $value, string $label): string
    {
        return $this->text(self::settingKey($type, $setting).'.choices.'.$value, $label);
    }

    private function text(string $key, string $fallback): string
    {
        $catalogue = $this->translator->getCatalogue();

        // has() looks into the fallback catalogues too: English has none of these keys.
        return $catalogue->has($key, self::DOMAIN) ? $catalogue->get($key, self::DOMAIN) : $fallback;
    }

    private static function settingKey(FieldTypeInterface $type, SettingDefinition $setting): string
    {
        return $type->name().'.settings.'.$setting->key;
    }
}
