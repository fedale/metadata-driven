<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * The translation keys of the metadata. Each text shown to the users has one, derived from
 * where it is defined; the metadata holds its text in the default language.
 *
 * The prefix says what the key translates, so two kinds of things never share a key.
 */
final class TranslationKey
{
    /** A text of its own, placed by a {t:name} token: "text.<name>". */
    public const string TEXT = 'text.';
    /** The name of a text: the part of a {t:name} token after "t:". */
    public const string TEXT_NAME_PATTERN = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/';

    public static function objectSingular(string $object): string
    {
        return 'object.'.$object.'.singular';
    }

    public static function objectPlural(string $object): string
    {
        return 'object.'.$object.'.plural';
    }

    public static function field(string $object, string $field): string
    {
        return 'field.'.$object.'.'.$field;
    }

    public static function option(string $object, string $field, string $value): string
    {
        return 'option.'.$object.'.'.$field.'.'.$value;
    }

    /** The title of the list of records pointing at a record through a relation. */
    public static function inverse(string $object, string $field): string
    {
        return 'inverse.'.$object.'.'.$field;
    }

    /** The message shown when a value does not match the pattern of a text field. */
    public static function patternMessage(string $object, string $field): string
    {
        return 'message.'.$object.'.'.$field;
    }

    public static function form(string $form): string
    {
        return 'form.'.$form;
    }

    /**
     * @param list<string> $path the section names from the root down, e.g. ["profile", "phones"]
     */
    public static function section(string $form, array $path): string
    {
        return 'section.'.$form.'.'.implode('.', $path);
    }

    /**
     * The description of the root of a form ($path []) or of one of its sections.
     *
     * @param list<string> $path the section names from the root down; [] for the root
     */
    public static function description(string $form, array $path): string
    {
        return 'description.'.implode('.', [$form, ...$path]);
    }

    /**
     * @param list<string> $path the section of the service field, from the root down; [] for the root
     */
    public static function serviceField(string $form, array $path, string $name): string
    {
        return 'service.'.$form.'.'.implode('.', [...$path, $name]);
    }

    public static function text(string $name): string
    {
        return self::TEXT.$name;
    }

    public static function isText(string $key): bool
    {
        return str_starts_with($key, self::TEXT);
    }
}
