<?php

declare(strict_types=1);

namespace App\TemplateFormat\FieldTypes;

use App\TemplateFormat\Format;

/**
 * The field types the format knows, and the settings of each, from field-types.v1.json.
 *
 * The format checks a template's settings with it, without the app: the catalog can refuse a
 * broken template on publish. In a template, settings are partial (what is left out takes its
 * default), and a setting naming an object or a field holds the id of an item of the template.
 */
final class FieldTypeCatalog
{
    private const string FILE = __DIR__.'/../Resources/field-types.v1.json';

    /** @var array<string, array<string, array<string, mixed>>> */
    private readonly array $types;

    public function __construct()
    {
        $decoded = json_decode((string) file_get_contents(self::FILE), true, flags: \JSON_THROW_ON_ERROR);
        $types = [];
        foreach (\is_array($decoded) ? $decoded : [] as $type => $settings) {
            // "$comment" documents the file.
            if (!\is_string($type) || str_starts_with($type, '$') || !\is_array($settings)) {
                continue;
            }
            $types[$type] = [];
            foreach ($settings as $key => $setting) {
                if (\is_string($key) && \is_array($setting)) {
                    $types[$type][$key] = self::stringKeys($setting);
                }
            }
        }
        $this->types = $types;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->types);
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /**
     * @return array<string, array<string, mixed>> each setting's description, by key
     */
    public function settings(string $type): array
    {
        return $this->types[$type] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(string $type): array
    {
        return array_map(static fn (array $setting): mixed => $setting['default'] ?? null, $this->settings($type));
    }

    /**
     * Only the settings that differ from their default: what an export writes.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public function withoutDefaults(string $type, array $settings): array
    {
        $defaults = $this->defaults($type);

        return array_filter($settings, static fn (mixed $value, string $key): bool => !\array_key_exists($key, $defaults) || $defaults[$key] !== $value, \ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The problems of a field's settings, by setting key ("" for the field type itself). A
     * reference is checked to be an id here; whether it names an item of the template is the
     * PackageChecker's business (references()).
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, list<string>>
     */
    public function check(string $type, array $settings): array
    {
        if (!$this->has($type)) {
            return ['' => [\sprintf('Unknown field type "%s" (known: %s).', $type, implode(', ', $this->names()))]];
        }

        $problems = [];
        $described = $this->settings($type);
        foreach (array_keys(array_diff_key($settings, $described)) as $key) {
            $problems[$key][] = [] === $described
                ? \sprintf('Unknown setting: type "%s" has no settings.', $type)
                : \sprintf('Unknown setting (type "%s" has %s).', $type, implode(', ', array_keys($described)));
        }
        foreach ($described as $key => $setting) {
            $required = 'object' === $setting['kind'] && true !== ($setting['nullable'] ?? false);
            if (!\array_key_exists($key, $settings)) {
                if ($required) {
                    $problems[$key][] = 'Required: the id of an object of the template.';
                }
                continue;
            }
            $message = self::problem($setting, $settings[$key]);
            if (null !== $message) {
                $problems[$key][] = $message;
            }
        }

        return $problems;
    }

    /**
     * The settings of a field that name other items, as given (null ones left out).
     *
     * @param array<string, mixed> $settings
     *
     * @return list<SettingReference>
     */
    public function references(string $type, array $settings): array
    {
        $references = [];
        foreach ($this->settings($type) as $key => $setting) {
            $value = $settings[$key] ?? null;
            $kind = ReferenceKind::tryFrom(\is_string($setting['kind'] ?? null) ? $setting['kind'] : '');
            if (null === $kind || !\is_string($value)) {
                continue;
            }
            $of = ReferenceKind::Field === $kind && \is_string($setting['of'] ?? null) ? FieldOwner::from($setting['of']) : null;
            $references[] = new SettingReference($key, $kind, $value, $of);
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $setting
     */
    private static function problem(array $setting, mixed $value): ?string
    {
        if (null === $value) {
            return true === ($setting['nullable'] ?? false) ? null : 'Must not be null.';
        }

        return match ($setting['kind']) {
            'integer' => !\is_int($value) ? 'Must be an integer.' : self::outOfRange($setting, $value),
            'boolean' => \is_bool($value) ? null : 'Must be true or false.',
            'string' => \is_string($value) ? null : 'Must be a string.',
            'choice' => \in_array($value, self::choices($setting), true) ? null : \sprintf('Must be one of %s.', implode(', ', self::choices($setting))),
            'object', 'field' => \is_string($value) && 1 === preg_match(Format::ID_PATTERN, $value)
                ? null
                : \sprintf('Must be the id of %s of the template.', 'object' === $setting['kind'] ? 'an object' : 'a field'),
            'options' => self::optionsProblem($value),
            default => \sprintf('The catalog describes this setting with an unknown kind "%s".', \is_string($setting['kind']) ? $setting['kind'] : '?'),
        };
    }

    /**
     * @param array<string, mixed> $setting
     */
    private static function outOfRange(array $setting, int $value): ?string
    {
        $min = \is_int($setting['min'] ?? null) ? $setting['min'] : null;
        $max = \is_int($setting['max'] ?? null) ? $setting['max'] : null;
        if ((null !== $min && $value < $min) || (null !== $max && $value > $max)) {
            return match (true) {
                null === $max => \sprintf('Must be at least %d.', $min),
                null === $min => \sprintf('Must be at most %d.', $max),
                default => \sprintf('Must be between %d and %d.', $min, $max),
            };
        }

        return null;
    }

    /**
     * @param array<string, mixed> $setting
     *
     * @return list<string>
     */
    private static function choices(array $setting): array
    {
        return \is_array($setting['choices'] ?? null) ? array_values(array_filter($setting['choices'], is_string(...))) : [];
    }

    /**
     * @param array<mixed> $map
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $map): array
    {
        return array_filter($map, is_string(...), \ARRAY_FILTER_USE_KEY);
    }

    private static function optionsProblem(mixed $value): ?string
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return 'Must be a list of options, each with a value and a label.';
        }
        foreach ($value as $option) {
            if (!\is_array($option) || !\is_string($option['value'] ?? null) || '' === $option['value'] || !\is_string($option['label'] ?? null)
                || [] !== array_diff_key($option, ['value' => true, 'label' => true])) {
                return 'Every option is a map with a value (a non-empty string) and a label, nothing else.';
            }
        }

        return null;
    }
}
