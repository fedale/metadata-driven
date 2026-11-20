<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Form;

use App\Module\Metadata\Application\Translation\DefinedTexts;
use App\Module\Metadata\Contract\Dto\FileListSettings;
use App\Module\Metadata\Contract\Dto\FormNodeMode;
use App\Module\Metadata\Contract\Dto\ManyToManySettings;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\RelationSettings;
use App\Module\Metadata\Contract\Dto\ServiceFieldAsk;
use App\Module\Metadata\Contract\Dto\ViewDefinitionDto;
use App\Module\Metadata\Contract\Exception\InvalidFieldSettings;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use App\Module\Metadata\Contract\FieldTypeCatalogInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Domain\Model\MachineName;
use App\Shared\Message\Message;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Turns the YAML definition of a form into its compiled form, checked against the
 * objects: every object, field and relation it names must exist and fit together.
 *
 * Every error is collected, prefixed with the path of its node ("profile.phones"), and
 * reported at once under the "definition" input, as a Message to show in the user's language.
 *
 *     label: User registration
 *     object: account
 *     fields: [username, email, password, active]      # optional: every field when omitted
 *     service_fields:                                  # optional: validated, never stored
 *       - { name: terms, type: boolean, label: I accept the terms, required: true, ask: always }
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
    private const int MAX_DESCRIPTION_LENGTH = 300;
    private const int MAX_COLUMNS = 4;
    private const array ROOT_KEYS = ['label', 'description', 'object', 'fields', 'required', 'service_fields', 'columns', 'layout', 'sections'];
    private const array SECTION_KEYS = ['name', 'label', 'description', 'object', 'via', 'mode', 'min', 'max', 'inline', 'fields', 'required', 'service_fields', 'columns', 'layout', 'sections'];
    private const array SERVICE_FIELD_KEYS = ['name', 'type', 'label', 'required', 'ask', 'settings'];

    /** @var list<string|Message> */
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
            throw new MetadataInputInvalid([self::INPUT => [new Message('metadata.form.invalid_yaml', 'Invalid YAML: %error%', ['error' => $e->getMessage()])]]);
        }
        if (!\is_array($definition) || array_is_list($definition)) {
            throw new MetadataInputInvalid([self::INPUT => [new Message('metadata.form.not_a_map', 'The definition must be a map with at least "label" and "object".')]]);
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
            $this->errors[] = new Message('metadata.form.object_unknown', '%path%: "object" must name an existing object, "%object%" does not exist.', ['path' => $path, 'object' => \is_scalar($objectName) ? (string) $objectName : '']);
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
            'description' => $this->description($path, $node['description'] ?? null),
            'columns' => $this->columns($path, $node['columns'] ?? 1),
        ];
        if (null !== $parent) {
            $inline = $node['inline'] ?? false;
            if (!\is_bool($inline)) {
                $this->errors[] = new Message('metadata.form.inline_invalid', '%path%: "inline" must be true or false.', ['path' => $path]);
            }
            $compiled['inline'] = true === $inline;
        }

        $sections = $node['sections'] ?? [];
        if (!\is_array($sections) || !array_is_list($sections)) {
            $this->errors[] = new Message('metadata.form.sections_invalid', '%path%: "sections" must be a list.', ['path' => $path]);
            $sections = [];
        }
        if ([] !== $sections && $depth + 1 >= self::MAX_DEPTH) {
            $this->errors[] = new Message('metadata.form.sections_too_deep', '%path%: sections may be nested %levels% levels deep at most.', ['path' => $path, 'levels' => self::MAX_DEPTH - 1]);
            $sections = [];
        }
        $names = [];
        foreach ($sections as $index => $section) {
            if (!\is_array($section) || array_is_list($section)) {
                $this->errors[] = new Message('metadata.form.section_invalid', '%path%: section #%position% must be a map.', ['path' => $path, 'position' => $index + 1]);
                continue;
            }
            $sectionName = $this->machineName($path, 'section', $section['name'] ?? null);
            $sectionPath = ('(root)' === $path ? '' : $path.'.').($sectionName ?? '#'.($index + 1));
            if (null !== $sectionName && isset($names[$sectionName])) {
                $this->errors[] = new Message('metadata.form.section_twice', '%path%: the section name "%name%" is used twice.', ['path' => $path, 'name' => $sectionName]);
            }
            $names[(string) $sectionName] = true;
            $this->unknownKeys($sectionPath, $section, self::SECTION_KEYS);
            if (null === $object) {
                continue;
            }
            $sectionLabel = $this->label($sectionPath, $section['label'] ?? null);
            $compiled['sections'][] = $this->node($sectionPath, $section, $sectionName ?? '', $sectionLabel, $object, $depth + 1);
        }

        if ($compiled['columns'] > 1 && ($compiled['inline'] || isset($node['layout']))) {
            $this->errors[] = new Message('metadata.form.columns_with_layout', '%path%: "columns" lays out the fields when there is no "layout" nor "inline": a layout places them by itself.', ['path' => $path]);
        }
        if (isset($node['layout'])) {
            if ($compiled['inline']) {
                $this->errors[] = new Message('metadata.form.layout_and_inline', '%path%: "layout" and "inline" exclude each other: an inline row lays its fields out by itself.', ['path' => $path]);
            } elseif (null !== $object) {
                $sectionNames = array_keys(array_filter($names, static fn (string $name): bool => '' !== $name, \ARRAY_FILTER_USE_KEY));
                // The sections of the root are cards of their own, after the root's: its layout
                // places its fields only.
                $placedSections = null === $parent && \is_string($node['layout']) ? array_intersect(ViewDefinitionDto::tokens($node['layout']), $sectionNames) : [];
                foreach ($placedSections as $section) {
                    $this->errors[] = new Message('metadata.form.layout_places_root_section', '%path%: the layout places {%section%}, a section of the root: those are cards of their own, shown after the root\'s fields; remove the token.', ['path' => $path, 'section' => $section]);
                }
                $compiled['layout'] = [] !== $placedSections ? null : $this->layout($path, $node['layout'], [
                    'field' => null === $fields ? array_values(array_diff(array_keys($object->fields), [$via])) : $fields,
                    'service field' => array_column($compiled['service'], 'name'),
                    'section' => null === $parent ? [] : $sectionNames,
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
            $this->errors[] = new Message('metadata.form.layout_invalid', '%path%: "layout" must be an HTML text of 1 to %max% characters.', ['path' => $path, 'max' => self::MAX_LAYOUT_LENGTH]);

            return null;
        }
        $valid = true;
        // A token stands for one thing: a section named like a field would make it ambiguous.
        $kinds = [];
        foreach ($names as $kind => $list) {
            foreach ($list as $name) {
                if (isset($kinds[$name])) {
                    $this->errors[] = new Message('metadata.form.layout_name_ambiguous', '%path%: "%name%" names both %kind% and %other_kind%: rename one, a {%name%} token would be ambiguous.', ['path' => $path, 'name' => $name, 'kind' => self::kind($kinds[$name]), 'other_kind' => self::kind($kind)]);
                    $valid = false;
                }
                $kinds[$name] = $kind;
            }
        }

        $layout = $this->sanitizer->sanitize($layout);
        $tokens = ViewDefinitionDto::tokens($layout);
        foreach ($tokens as $token) {
            if (!isset($kinds[$token])) {
                $this->errors[] = new Message('metadata.form.layout_token_unknown', '%path%: the layout places {%token%}, which is no field, service field or section of this node (%names%).', ['path' => $path, 'token' => $token, 'names' => implode(', ', array_keys($kinds))]);
                $valid = false;
            }
        }
        foreach (array_count_values($tokens) as $token => $count) {
            if ($count > 1) {
                $this->errors[] = new Message('metadata.form.layout_token_twice', '%path%: the layout places {%token%} more than once.', ['path' => $path, 'token' => (string) $token]);
                $valid = false;
            }
        }
        if (\count(ViewDefinitionDto::tokens(strip_tags($layout))) !== \count($tokens)) {
            $this->errors[] = new Message('metadata.form.layout_token_in_tag', '%path%: {name} tokens go in the text of the layout, not inside a tag or an attribute.', ['path' => $path]);
            $valid = false;
        }
        if (null !== $error = $this->texts->errorFor($path, $layout)) {
            $this->errors[] = $error;
            $valid = false;
        }

        return $valid ? $layout : null;
    }

    /**
     * What a layout token may name, with its article, for the error about a name used twice.
     */
    private static function kind(string $kind): Message
    {
        return match ($kind) {
            'service field' => new Message('metadata.form.kind.service_field', 'a service field'),
            'section' => new Message('metadata.form.kind.section', 'a section'),
            default => new Message('metadata.form.kind.field', 'a field'),
        };
    }

    /**
     * The relation field of the section's object that points at the parent's object.
     */
    private function via(string $path, mixed $via, ?ObjectDefinitionDto $object, ObjectDefinitionDto $parent): ?string
    {
        if (!\is_string($via) || '' === $via) {
            $this->errors[] = new Message('metadata.form.via_missing', '%path%: "via" must name the relation field pointing at "%parent%".', ['path' => $path, 'parent' => $parent->name]);

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
            $parameters = ['path' => $path, 'via' => $via, 'object' => $object->name, 'parent' => $parent->name];
            $this->errors[] = [] === $candidates
                ? new Message('metadata.form.via_invalid', '%path%: "%via%" is not a relation field of "%object%" pointing at "%parent%" (it has none: add one first).', $parameters)
                : new Message('metadata.form.via_invalid_with_candidates', '%path%: "%via%" is not a relation field of "%object%" pointing at "%parent%" (candidates: %candidates%).', [...$parameters, 'candidates' => implode(', ', $candidates)]);
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
            $this->errors[] = new Message('metadata.form.mode_invalid', '%path%: "mode" must be "one" or "many".', ['path' => $path]);

            return [FormNodeMode::One, 0, 1];
        }
        if (FormNodeMode::One === $mode) {
            if (isset($node['min']) || isset($node['max'])) {
                $this->errors[] = new Message('metadata.form.min_max_without_many', '%path%: "min" and "max" apply to "mode: many" only.', ['path' => $path]);
            }

            return [$mode, 0, 1];
        }

        $min = $node['min'] ?? 0;
        $max = $node['max'] ?? null;
        if (!\is_int($min) || $min < 0) {
            $this->errors[] = new Message('metadata.form.min_invalid', '%path%: "min" must be an integer, 0 or more.', ['path' => $path]);
            $min = 0;
        }
        if (null !== $max && (!\is_int($max) || $max < max(1, $min))) {
            $this->errors[] = new Message('metadata.form.max_invalid', '%path%: "max" must be an integer, at least 1 and at least "min".', ['path' => $path]);
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
            $this->errors[] = new Message('metadata.form.fields_invalid', '%path%: "fields" must be a non-empty list of field names.', ['path' => $path]);

            return null;
        }
        $names = [];
        foreach ($fields as $field) {
            if (!\is_string($field)) {
                $this->errors[] = new Message('metadata.form.fields_not_names', '%path%: "fields" must list field names.', ['path' => $path]);
                continue;
            }
            if (null !== $object && !$object->hasField($field)) {
                $this->errors[] = new Message('metadata.form.field_unknown', '%path%: object "%object%" has no field "%field%".', ['path' => $path, 'object' => $object->name, 'field' => $field]);
            } elseif ($field === $via) {
                $this->errors[] = new Message('metadata.form.field_is_via', '%path%: "%field%" is the "via" field, set by the form itself: leave it out of "fields".', ['path' => $path, 'field' => $field]);
            } elseif (isset($names[$field])) {
                $this->errors[] = new Message('metadata.form.field_twice', '%path%: the field "%field%" is listed twice.', ['path' => $path, 'field' => $field]);
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
                    $this->errors[] = new Message('metadata.form.required_field_missing', '%path%: "%field%" is required by object "%object%": list it in "fields".', ['path' => $path, 'field' => $name, 'object' => $object->name]);
                }
            }
        }
        if (!\is_array($required) || !array_is_list($required)) {
            $this->errors[] = new Message('metadata.form.required_invalid', '%path%: "required" must be a list of field names.', ['path' => $path]);

            return [];
        }
        $names = [];
        foreach ($required as $name) {
            $shown = \is_string($name) && null !== $object && $object->hasField($name) && $name !== $via && (null === $fields || \in_array($name, $fields, true));
            if (!$shown) {
                $this->errors[] = new Message('metadata.form.required_not_shown', '%path%: "required" may only name fields the form shows, "%field%" is not one.', ['path' => $path, 'field' => \is_scalar($name) ? (string) $name : '']);
                continue;
            }
            $names[$name] = true;
        }

        return array_keys($names);
    }

    /**
     * @return list<array{name: string, type: string, label: string, required: bool, ask: string, settings: array<string, mixed>}>
     */
    private function serviceFields(string $path, mixed $fields): array
    {
        if (!\is_array($fields) || !array_is_list($fields)) {
            $this->errors[] = new Message('metadata.form.service_fields_invalid', '%path%: "service_fields" must be a list.', ['path' => $path]);

            return [];
        }
        $compiled = [];
        $names = [];
        foreach ($fields as $index => $field) {
            if (!\is_array($field) || array_is_list($field)) {
                $this->errors[] = new Message('metadata.form.service_field_invalid', '%path%: service field #%position% must be a map.', ['path' => $path, 'position' => $index + 1]);
                continue;
            }
            $name = $this->machineName($path, 'service field', $field['name'] ?? null);
            $fieldPath = $path.' > '.($name ?? '#'.($index + 1));
            $this->unknownKeys($fieldPath, $field, self::SERVICE_FIELD_KEYS);
            if (null !== $name && isset($names[$name])) {
                $this->errors[] = new Message('metadata.form.service_field_twice', '%path%: the service field "%name%" is declared twice.', ['path' => $path, 'name' => $name]);
            }
            $names[(string) $name] = true;

            $type = $field['type'] ?? null;
            $settings = $field['settings'] ?? [];
            // A service field belongs to no record: it cannot point at other records, and a file
            // would be uploaded only to be thrown away.
            $excluded = [RelationSettings::FIELD_TYPE, ManyToManySettings::FIELD_TYPE, FileListSettings::FIELD_TYPE];
            if (!\is_string($type) || !$this->fieldTypes->supports($type) || \in_array($type, $excluded, true)) {
                $this->errors[] = new Message('metadata.form.service_field_type_invalid', '%path%: "type" must be a field type other than relation, many_to_many and file (%types%).', ['path' => $fieldPath, 'types' => implode(', ', array_diff($this->fieldTypes->names(), $excluded))]);
                continue;
            }
            if (!\is_array($settings) || ([] !== $settings && array_is_list($settings))) {
                $this->errors[] = new Message('metadata.form.service_field_settings_invalid', '%path%: "settings" must be a map.', ['path' => $fieldPath]);
                continue;
            }
            /** @var array<string, mixed> $settings a map: its keys are strings */
            $required = $field['required'] ?? false;
            if (!\is_bool($required)) {
                $this->errors[] = new Message('metadata.form.service_field_required_invalid', '%path%: "required" must be true or false.', ['path' => $fieldPath]);
            }
            $askValue = $field['ask'] ?? ServiceFieldAsk::Always->value;
            $ask = \is_string($askValue) ? ServiceFieldAsk::tryFrom($askValue) : null;
            if (null === $ask) {
                $this->errors[] = new Message('metadata.form.service_field_ask_invalid', '%path%: "ask" must be one of %values%.', ['path' => $fieldPath, 'values' => implode(', ', array_column(ServiceFieldAsk::cases(), 'value'))]);
            }
            try {
                $settings = $this->fieldTypes->resolveSettings($type, $settings);
            } catch (InvalidFieldSettings $e) {
                foreach ($e->errors as $error) {
                    $this->errors[] = new Message('metadata.form.service_field_setting_invalid', '%path%: %error%', ['path' => $fieldPath, 'error' => $error]);
                }
                continue;
            }

            $compiled[] = [
                'name' => (string) $name,
                'type' => $type,
                'label' => $this->label($fieldPath, $field['label'] ?? null),
                'required' => true === $required,
                'ask' => ($ask ?? ServiceFieldAsk::Always)->value,
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
            $parameters = ['path' => $path, 'max' => MachineName::MAX_LENGTH];
            $this->errors[] = 'section' === $what
                ? new Message('metadata.form.section_name_invalid', '%path%: a section needs a "name" of lowercase letters, digits and single underscores (2 to %max% characters).', $parameters)
                : new Message('metadata.form.service_field_name_invalid', '%path%: a service field needs a "name" of lowercase letters, digits and single underscores (2 to %max% characters).', $parameters);

            return null;
        }

        return $name;
    }

    private function description(string $path, mixed $description): ?string
    {
        if (null === $description) {
            return null;
        }
        if (!\is_string($description) || '' === trim($description) || mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            $this->errors[] = new Message('metadata.form.description_invalid', '%path%: "description" must be a text of 1 to %max% characters.', ['path' => $path, 'max' => self::MAX_DESCRIPTION_LENGTH]);

            return null;
        }

        return trim($description);
    }

    private function columns(string $path, mixed $columns): int
    {
        if (!\is_int($columns) || $columns < 1 || $columns > self::MAX_COLUMNS) {
            $this->errors[] = new Message('metadata.form.columns_invalid', '%path%: "columns" must be a number from 1 to %max%.', ['path' => $path, 'max' => self::MAX_COLUMNS]);

            return 1;
        }

        return $columns;
    }

    private function label(string $path, mixed $label): string
    {
        if (!\is_string($label) || '' === trim($label) || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            $this->errors[] = new Message('metadata.form.label_invalid', '%path%: "label" must be a text of 1 to %max% characters.', ['path' => $path, 'max' => self::MAX_LABEL_LENGTH]);

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
            $this->errors[] = new Message('metadata.form.unknown_key', '%path%: unknown key "%key%" (allowed: %allowed%).', ['path' => $path, 'key' => (string) $key, 'allowed' => implode(', ', $allowed)]);
        }
    }
}
