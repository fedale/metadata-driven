<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Form;

use App\Module\Metadata\Application\Translation\DefinedTexts;
use App\Module\Metadata\Contract\Dto\FormNodeMode;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\RelationSettings;
use App\Module\Metadata\Contract\Dto\ViewDefinitionDto;
use App\Module\Metadata\Contract\Exception\InvalidFieldSettings;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use App\Module\Metadata\Contract\FieldTypeCatalogInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Domain\Model\MachineName;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Turns the YAML definition of a composite form into its compiled form, checked against the
 * objects: every object, field and relation it names must exist and fit together.
 *
 * Every error is collected, prefixed with the path of its node ("profile.phones"), and
 * reported at once under the "definition" input.
 *
 *     label: User registration
 *     object: account
 *     fields: [username, email, password, active]      # optional: every field when omitted
 *     service_fields:                                  # optional: validated, never stored
 *       - { name: terms, type: boolean, label: I accept the terms, required: true }
 *     sections:
 *       - name: profile
 *         label: Profile
 *         object: profile
 *         via: account            # relation field of profile pointing at account
 *         mode: one               # one (default) or many
 *         sections:
 *           - { name: phones, label: Phones, object: phone, via: profile, mode: many, min: 1, inline: true }
 *
 * "inline: true" lays the fields of a section out on one line (each row, for "mode: many").
 * "required: [city]" makes fields required in this form only; the fields their object
 * requires are required anyway, so "fields" may not leave them out.
 * "layout" places the fields, service fields and sections of a node in HTML, with a {name}
 * token each, as the views of the record form do; it is sanitized here, once.
 */
final class FormDefinitionCompiler
{
    public const string INPUT = 'definition';
    private const int MAX_DEPTH = 5;
    private const int MAX_LABEL_LENGTH = 100;
    private const int MAX_LAYOUT_LENGTH = 20000;
    private const array ROOT_KEYS = ['label', 'object', 'fields', 'required', 'service_fields', 'layout', 'sections'];
    private const array SECTION_KEYS = ['name', 'label', 'object', 'via', 'mode', 'min', 'max', 'inline', 'fields', 'required', 'service_fields', 'layout', 'sections'];
    private const array SERVICE_FIELD_KEYS = ['name', 'type', 'label', 'required', 'settings'];

    /** @var list<string> */
    private array $errors = [];

    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
        private readonly FieldTypeCatalogInterface $fieldTypes,
        #[Target('view_layout')]
        private readonly HtmlSanitizerInterface $sanitizer,
        private readonly DefinedTexts $texts,
    ) {
    }

    /**
     * @return array{label: string, root: array<string, mixed>}
     *
     * @throws MetadataInputInvalid
     */
    public function compile(string $source): array
    {
        $this->errors = [];
        try {
            $definition = Yaml::parse($source);
        } catch (ParseException $e) {
            throw new MetadataInputInvalid([self::INPUT => [\sprintf('Invalid YAML: %s', $e->getMessage())]]);
        }
        if (!\is_array($definition) || array_is_list($definition)) {
            throw new MetadataInputInvalid([self::INPUT => ['The definition must be a map with at least "label" and "object".']]);
        }

        $this->unknownKeys('(root)', $definition, self::ROOT_KEYS);
        $label = $this->label('(root)', $definition['label'] ?? null);
        $root = $this->node('(root)', $definition, 'root', $label, null, 0);
        if ([] !== $this->errors) {
            throw new MetadataInputInvalid([self::INPUT => $this->errors]);
        }

        return ['label' => $label, 'root' => $root];
    }

    /**
     * @param array<mixed>             $node
     * @param ObjectDefinitionDto|null $parent the object of the parent node; null for the root
     *
     * @return array<string, mixed>
     */
    private function node(string $path, array $node, string $name, string $label, ?ObjectDefinitionDto $parent, int $depth): array
    {
        $objectName = $node['object'] ?? null;
        $object = \is_string($objectName) && $this->metadata->hasObject($objectName) ? $this->metadata->getObject($objectName) : null;
        if (null === $object) {
            $this->errors[] = \sprintf('%s: "object" must name an existing object, "%s" does not exist.', $path, \is_scalar($objectName) ? $objectName : '');
        }

        $via = null === $parent ? null : $this->via($path, $node['via'] ?? null, $object, $parent);
        [$mode, $min, $max] = null === $parent ? [FormNodeMode::One, 1, 1] : $this->cardinality($path, $node);

        $compiled = [
            'name' => $name,
            'label' => $label,
            'object' => \is_string($objectName) ? $objectName : '',
            'via' => $via,
            'mode' => $mode->value,
            'min' => $min,
            'max' => $max,
            'fields' => $fields = $this->fields($path, $node['fields'] ?? null, $object, $via),
            'required' => $this->required($path, $node['required'] ?? [], $object, $fields, $via),
            'service' => $this->serviceFields($path, $node['service_fields'] ?? []),
            'sections' => [],
            'inline' => false,
            'layout' => null,
        ];
        if (null !== $parent) {
            $inline = $node['inline'] ?? false;
            if (!\is_bool($inline)) {
                $this->errors[] = \sprintf('%s: "inline" must be true or false.', $path);
            }
            $compiled['inline'] = true === $inline;
        }

        $sections = $node['sections'] ?? [];
        if (!\is_array($sections) || !array_is_list($sections)) {
            $this->errors[] = \sprintf('%s: "sections" must be a list.', $path);
            $sections = [];
        }
        if ([] !== $sections && $depth + 1 >= self::MAX_DEPTH) {
            $this->errors[] = \sprintf('%s: sections may be nested %d levels deep at most.', $path, self::MAX_DEPTH - 1);
            $sections = [];
        }
        $names = [];
        foreach ($sections as $index => $section) {
            if (!\is_array($section) || array_is_list($section)) {
                $this->errors[] = \sprintf('%s: section #%d must be a map.', $path, $index + 1);
                continue;
            }
            $sectionName = $this->machineName($path, 'section', $section['name'] ?? null);
            $sectionPath = ('(root)' === $path ? '' : $path.'.').($sectionName ?? '#'.($index + 1));
            if (null !== $sectionName && isset($names[$sectionName])) {
                $this->errors[] = \sprintf('%s: the section name "%s" is used twice.', $path, $sectionName);
            }
            $names[(string) $sectionName] = true;
            $this->unknownKeys($sectionPath, $section, self::SECTION_KEYS);
            if (null === $object) {
                continue;
            }
            $sectionLabel = $this->label($sectionPath, $section['label'] ?? null);
            $compiled['sections'][] = $this->node($sectionPath, $section, $sectionName ?? '', $sectionLabel, $object, $depth + 1);
        }

        if (isset($node['layout'])) {
            if ($compiled['inline']) {
                $this->errors[] = \sprintf('%s: "layout" and "inline" exclude each other: an inline row lays its fields out by itself.', $path);
            } elseif (null !== $object) {
                $compiled['layout'] = $this->layout($path, $node['layout'], [
                    'field' => null === $fields ? array_values(array_diff(array_keys($object->fields), [$via])) : $fields,
                    'service field' => array_column($compiled['service'], 'name'),
                    'section' => array_keys(array_filter($names, static fn (string $name): bool => '' !== $name, \ARRAY_FILTER_USE_KEY)),
                ]);
            }
        }

        return $compiled;
    }

    /**
     * Sanitizes the layout of a node and checks its tokens: each names a field, service field or
     * section of the node, once, in the text (not inside a tag).
     *
     * @param array<string, list<string>> $names the names a token may use, by what they name
     *
     * @return string|null the sanitized layout; null when invalid
     */
    private function layout(string $path, mixed $layout, array $names): ?string
    {
        if (!\is_string($layout) || '' === trim($layout) || mb_strlen($layout) > self::MAX_LAYOUT_LENGTH) {
            $this->errors[] = \sprintf('%s: "layout" must be an HTML text of 1 to %d characters.', $path, self::MAX_LAYOUT_LENGTH);

            return null;
        }
        $valid = true;
        // A token stands for one thing: a section named like a field would make it ambiguous.
        $kinds = [];
        foreach ($names as $kind => $list) {
            foreach ($list as $name) {
                if (isset($kinds[$name])) {
                    $this->errors[] = \sprintf('%s: "%s" names both a %s and a %s: rename one, a {%s} token would be ambiguous.', $path, $name, $kinds[$name], $kind, $name);
                    $valid = false;
                }
                $kinds[$name] = $kind;
            }
        }

        $layout = $this->sanitizer->sanitize($layout);
        $tokens = ViewDefinitionDto::tokens($layout);
        foreach ($tokens as $token) {
            if (!isset($kinds[$token])) {
                $this->errors[] = \sprintf('%s: the layout places {%s}, which is no field, service field or section of this node (%s).', $path, $token, implode(', ', array_keys($kinds)));
                $valid = false;
            }
        }
        foreach (array_count_values($tokens) as $token => $count) {
            if ($count > 1) {
                $this->errors[] = \sprintf('%s: the layout places {%s} more than once.', $path, $token);
                $valid = false;
            }
        }
        if (\count(ViewDefinitionDto::tokens(strip_tags($layout))) !== \count($tokens)) {
            $this->errors[] = \sprintf('%s: {name} tokens go in the text of the layout, not inside a tag or an attribute.', $path);
            $valid = false;
        }
        if (null !== $error = $this->texts->errorFor($path, $layout)) {
            $this->errors[] = $error;
            $valid = false;
        }

        return $valid ? $layout : null;
    }

    /**
     * The relation field of the section's object that points at the parent's object.
     */
    private function via(string $path, mixed $via, ?ObjectDefinitionDto $object, ObjectDefinitionDto $parent): ?string
    {
        if (!\is_string($via) || '' === $via) {
            $this->errors[] = \sprintf('%s: "via" must name the relation field pointing at "%s".', $path, $parent->name);

            return null;
        }
        if (null === $object) {
            return $via;
        }
        $relation = $object->hasField($via) ? $object->field($via)->relation() : null;
        if (null === $relation || $relation->target !== $parent->name) {
            $candidates = [];
            foreach ($object->fields as $name => $field) {
                if ($field->relation()?->target === $parent->name) {
                    $candidates[] = $name;
                }
            }
            $this->errors[] = \sprintf(
                '%s: "%s" is not a relation field of "%s" pointing at "%s"%s.',
                $path,
                $via,
                $object->name,
                $parent->name,
                [] === $candidates ? ' (it has none: add one first)' : \sprintf(' (candidates: %s)', implode(', ', $candidates)),
            );
        }

        return $via;
    }

    /**
     * @param array<mixed> $node
     *
     * @return array{FormNodeMode, int, int|null}
     */
    private function cardinality(string $path, array $node): array
    {
        $mode = FormNodeMode::tryFrom(\is_string($node['mode'] ?? null) ? $node['mode'] : FormNodeMode::One->value);
        if (null === $mode) {
            $this->errors[] = \sprintf('%s: "mode" must be "one" or "many".', $path);

            return [FormNodeMode::One, 0, 1];
        }
        if (FormNodeMode::One === $mode) {
            if (isset($node['min']) || isset($node['max'])) {
                $this->errors[] = \sprintf('%s: "min" and "max" apply to "mode: many" only.', $path);
            }

            return [$mode, 0, 1];
        }

        $min = $node['min'] ?? 0;
        $max = $node['max'] ?? null;
        if (!\is_int($min) || $min < 0) {
            $this->errors[] = \sprintf('%s: "min" must be an integer, 0 or more.', $path);
            $min = 0;
        }
        if (null !== $max && (!\is_int($max) || $max < max(1, $min))) {
            $this->errors[] = \sprintf('%s: "max" must be an integer, at least 1 and at least "min".', $path);
            $max = null;
        }

        return [$mode, $min, $max];
    }

    /**
     * @return list<string>|null null for every field
     */
    private function fields(string $path, mixed $fields, ?ObjectDefinitionDto $object, ?string $via): ?array
    {
        if (null === $fields) {
            return null;
        }
        if (!\is_array($fields) || !array_is_list($fields) || [] === $fields) {
            $this->errors[] = \sprintf('%s: "fields" must be a non-empty list of field names.', $path);

            return null;
        }
        $names = [];
        foreach ($fields as $field) {
            if (!\is_string($field)) {
                $this->errors[] = \sprintf('%s: "fields" must list field names.', $path);
                continue;
            }
            if (null !== $object && !$object->hasField($field)) {
                $this->errors[] = \sprintf('%s: object "%s" has no field "%s".', $path, $object->name, $field);
            } elseif ($field === $via) {
                $this->errors[] = \sprintf('%s: "%s" is the "via" field, set by the form itself: leave it out of "fields".', $path, $field);
            } elseif (isset($names[$field])) {
                $this->errors[] = \sprintf('%s: the field "%s" is listed twice.', $path, $field);
            }
            $names[$field] = true;
        }

        return array_keys($names);
    }

    /**
     * The fields the form requires on top of the object's own required fields.
     *
     * @param list<string>|null $fields the fields shown; null for every field
     *
     * @return list<string>
     */
    private function required(string $path, mixed $required, ?ObjectDefinitionDto $object, ?array $fields, ?string $via): array
    {
        if (null !== $object) {
            // A field the object requires must be in the form: the record could not be saved otherwise.
            foreach ($object->fields as $name => $field) {
                if ($field->isRequired && $name !== $via && null !== $fields && !\in_array($name, $fields, true)) {
                    $this->errors[] = \sprintf('%s: "%s" is required by object "%s": list it in "fields".', $path, $name, $object->name);
                }
            }
        }
        if (!\is_array($required) || !array_is_list($required)) {
            $this->errors[] = \sprintf('%s: "required" must be a list of field names.', $path);

            return [];
        }
        $names = [];
        foreach ($required as $name) {
            $shown = \is_string($name) && null !== $object && $object->hasField($name) && $name !== $via && (null === $fields || \in_array($name, $fields, true));
            if (!$shown) {
                $this->errors[] = \sprintf('%s: "required" may only name fields the form shows, "%s" is not one.', $path, \is_scalar($name) ? $name : '');
                continue;
            }
            $names[$name] = true;
        }

        return array_keys($names);
    }

    /**
     * @return list<array{name: string, type: string, label: string, required: bool, settings: array<string, mixed>}>
     */
    private function serviceFields(string $path, mixed $fields): array
    {
        if (!\is_array($fields) || !array_is_list($fields)) {
            $this->errors[] = \sprintf('%s: "service_fields" must be a list.', $path);

            return [];
        }
        $compiled = [];
        $names = [];
        foreach ($fields as $index => $field) {
            if (!\is_array($field) || array_is_list($field)) {
                $this->errors[] = \sprintf('%s: service field #%d must be a map.', $path, $index + 1);
                continue;
            }
            $name = $this->machineName($path, 'service field', $field['name'] ?? null);
            $fieldPath = $path.' > '.($name ?? '#'.($index + 1));
            $this->unknownKeys($fieldPath, $field, self::SERVICE_FIELD_KEYS);
            if (null !== $name && isset($names[$name])) {
                $this->errors[] = \sprintf('%s: the service field "%s" is declared twice.', $path, $name);
            }
            $names[(string) $name] = true;

            $type = $field['type'] ?? null;
            $settings = $field['settings'] ?? [];
            if (!\is_string($type) || !$this->fieldTypes->supports($type) || RelationSettings::FIELD_TYPE === $type) {
                $this->errors[] = \sprintf('%s: "type" must be a field type other than relation (%s).', $fieldPath, implode(', ', array_diff($this->fieldTypes->names(), [RelationSettings::FIELD_TYPE])));
                continue;
            }
            if (!\is_array($settings) || ([] !== $settings && array_is_list($settings))) {
                $this->errors[] = \sprintf('%s: "settings" must be a map.', $fieldPath);
                continue;
            }
            /** @var array<string, mixed> $settings a map: its keys are strings */
            $required = $field['required'] ?? false;
            if (!\is_bool($required)) {
                $this->errors[] = \sprintf('%s: "required" must be true or false.', $fieldPath);
            }
            try {
                $settings = $this->fieldTypes->resolveSettings($type, $settings);
            } catch (InvalidFieldSettings $e) {
                foreach ($e->errors as $error) {
                    $this->errors[] = \sprintf('%s: %s', $fieldPath, $error);
                }
                continue;
            }

            $compiled[] = [
                'name' => (string) $name,
                'type' => $type,
                'label' => $this->label($fieldPath, $field['label'] ?? null),
                'required' => true === $required,
                'settings' => $settings,
            ];
        }

        return $compiled;
    }

    private function machineName(string $path, string $what, mixed $name): ?string
    {
        $valid = \is_string($name)
            && \strlen($name) >= MachineName::MIN_LENGTH && \strlen($name) <= MachineName::MAX_LENGTH
            && 1 === preg_match(MachineName::PATTERN, $name);
        if (!$valid) {
            $this->errors[] = \sprintf('%s: a %s needs a "name" of lowercase letters, digits and single underscores (2 to %d characters).', $path, $what, MachineName::MAX_LENGTH);

            return null;
        }

        return $name;
    }

    private function label(string $path, mixed $label): string
    {
        if (!\is_string($label) || '' === trim($label) || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            $this->errors[] = \sprintf('%s: "label" must be a text of 1 to %d characters.', $path, self::MAX_LABEL_LENGTH);

            return '';
        }

        return trim($label);
    }

    /**
     * @param array<mixed> $map
     * @param list<string> $allowed
     */
    private function unknownKeys(string $path, array $map, array $allowed): void
    {
        foreach (array_diff(array_keys($map), $allowed) as $key) {
            $this->errors[] = \sprintf('%s: unknown key "%s" (allowed: %s).', $path, $key, implode(', ', $allowed));
        }
    }
}
