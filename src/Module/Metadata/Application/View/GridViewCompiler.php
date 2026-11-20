<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\View;

use App\Module\Metadata\Application\Translation\DefinedTexts;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\ViewDefinitionDto;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use App\Module\Metadata\Contract\FieldTypeCatalogInterface;
use App\Module\Metadata\Contract\GridRendererCatalogInterface;
use App\Module\Metadata\Domain\Model\MachineName;
use App\Shared\Message\Message;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Turns the YAML definition of a grid view into its compiled form, checked against the
 * object: every column names a readable field, or is a virtual column built from some.
 *
 *     columns:                                   # in order
 *       - username                               # a field: its label, filter and sort
 *       - { field: email, label: E-mail }
 *       - { field: bio, renderer: truncate, options: { length: 60 } }
 *       - { field: active, hidden: true }        # off until the user turns it on
 *       - tags                                   # a many-to-many or file field: never sortable
 *       - { name: fullname, label: Full name, template: '{firstname} {lastname}' }
 *     sort: { lastname: asc }                    # optional: the initial order
 *     page_size: 50                              # optional
 *
 * Every error is collected and reported at once under the "source" input, as a Message to show
 * in the user's language.
 */
final class GridViewCompiler
{
    public const string INPUT = 'source';
    public const array SYSTEM_SORT_COLUMNS = ['created_at', 'updated_at'];
    private const int MAX_LABEL_LENGTH = 100;
    private const int MAX_PAGE_SIZE = 500;
    private const array ROOT_KEYS = ['columns', 'sort', 'page_size'];
    private const array COLUMN_KEYS = ['field', 'name', 'template', 'label', 'renderer', 'options', 'hidden', 'sortable', 'filter'];

    /** @var list<string|Message> */
    private array $errors = [];

    public function __construct(
        private readonly FieldTypeCatalogInterface $fieldTypes,
        private readonly GridRendererCatalogInterface $renderers,
        private readonly DefinedTexts $texts,
    ) {
    }

    /**
     * @return array{columns: list<array<string, mixed>>, sort: array<string, string>, pageSize: int|null}
     *
     * @throws MetadataInputInvalid
     */
    public function compile(ObjectDefinitionDto $object, string $source): array
    {
        $this->errors = [];
        try {
            $definition = Yaml::parse($source);
        } catch (ParseException $e) {
            throw new MetadataInputInvalid([self::INPUT => [new Message('metadata.grid_view.invalid_yaml', 'Invalid YAML: %error%', ['error' => $e->getMessage()])]]);
        }
        if (!\is_array($definition) || array_is_list($definition)) {
            throw new MetadataInputInvalid([self::INPUT => [new Message('metadata.grid_view.not_a_map', 'The definition must be a map with at least "columns".')]]);
        }
        $this->unknownKeys('(grid)', $definition, self::ROOT_KEYS);

        $columns = [];
        $list = $definition['columns'] ?? null;
        if (!\is_array($list) || !array_is_list($list) || [] === $list) {
            $this->errors[] = new Message('metadata.grid_view.columns_invalid', '"columns" must be a non-empty list.');
            $list = [];
        }
        foreach ($list as $index => $column) {
            $compiled = $this->column($object, $index + 1, \is_string($column) ? ['field' => $column] : $column);
            if (null === $compiled) {
                continue;
            }
            $key = $compiled['key'];
            if (isset($columns[$key])) {
                $this->errors[] = new Message('metadata.grid_view.column_twice', 'Column "%column%" is listed twice.', ['column' => $key]);
                continue;
            }
            $columns[$key] = $compiled;
        }

        $compiled = [
            'columns' => array_values($columns),
            'sort' => $this->sort($definition['sort'] ?? [], $columns),
            'pageSize' => $this->pageSize($definition['page_size'] ?? null),
        ];
        if ([] !== $this->errors) {
            throw new MetadataInputInvalid([self::INPUT => $this->errors]);
        }

        return $compiled;
    }

    /**
     * @return array{key: string, field: string|null, template: string|null, sources: list<string>, label: string|null, renderer: string|null, options: array<string, mixed>, hidden: bool, sortable: bool, filter: bool}|null null when invalid
     */
    private function column(ObjectDefinitionDto $object, int $position, mixed $column): ?array
    {
        if (!\is_array($column) || array_is_list($column)) {
            $this->errors[] = new Message('metadata.grid_view.column_invalid', 'Column #%position% must be a field name or a map.', ['position' => $position]);

            return null;
        }
        // The path that prefixes the errors of the column: a Message, to name it in the user's language.
        $path = new Message('metadata.grid_view.column_at', 'Column #%position%', ['position' => $position]);
        $this->unknownKeys($path, $column, self::COLUMN_KEYS);

        $field = $column['field'] ?? null;
        $name = $column['name'] ?? null;
        $template = $column['template'] ?? null;
        $sources = [];
        if (null !== $field) {
            if (null !== $name || null !== $template) {
                $this->errors[] = new Message('metadata.grid_view.field_and_virtual', '%path%: a field column takes "field"; a virtual column takes "name" and "template", not both.', ['path' => $path]);

                return null;
            }
            if (!\is_string($field) || !$this->readable($object, $field, $path)) {
                return null;
            }
            $key = $field;
        } else {
            if (!\is_string($name) || 1 !== preg_match(MachineName::PATTERN, $name) || \strlen($name) > MachineName::MAX_LENGTH) {
                $this->errors[] = new Message('metadata.grid_view.column_unnamed', '%path%: give it a "field", or a "name" (lowercase letters, digits and single underscores) and a "template".', ['path' => $path]);

                return null;
            }
            $path = new Message('metadata.grid_view.column_named', 'Column "%column%"', ['column' => $name]);
            if ($object->hasField($name)) {
                $this->errors[] = new Message('metadata.grid_view.virtual_name_taken', '%path%: "%name%" is a field of "%object%": a virtual column needs a name of its own.', ['path' => $path, 'name' => $name, 'object' => $object->name]);

                return null;
            }
            if (!\is_string($template) || '' === trim($template) || mb_strlen($template) > self::MAX_LABEL_LENGTH * 2) {
                $this->errors[] = new Message('metadata.grid_view.template_invalid', '%path%: "template" must be a text with {field} tokens, at most %max% characters.', ['path' => $path, 'max' => self::MAX_LABEL_LENGTH * 2]);

                return null;
            }
            $sources = array_values(array_unique(ViewDefinitionDto::tokens($template)));
            if ([] === $sources) {
                $this->errors[] = new Message('metadata.grid_view.template_without_field', '%path%: the template places no {field}.', ['path' => $path]);

                return null;
            }
            foreach ($sources as $source) {
                if (!$this->readable($object, $source, $path)) {
                    return null;
                }
                if (null !== $object->field($source)->relation()) {
                    $this->errors[] = new Message('metadata.grid_view.template_relation', '%path%: {%field%} is a relation: show it in a column of its own.', ['path' => $path, 'field' => $source]);

                    return null;
                }
                // A many-to-many field (a set of records) or a file (an id) has no text to join.
                if (!$this->fieldTypes->isSortable($object->field($source)->type)) {
                    $this->errors[] = new Message('metadata.grid_view.template_not_joinable', '%path%: {%field%} is a %type% field: show it in a column of its own.', ['path' => $path, 'field' => $source, 'type' => str_replace('_', '-', $object->field($source)->type)]);

                    return null;
                }
            }
            $key = $name;
        }
        // The records a many-to-many field links to, and a file, have no order of their own.
        $sortable = $this->flag($path, $column, 'sortable', true);
        if (\is_string($field) && !$this->fieldTypes->isSortable($object->field($field)->type)) {
            if (true === ($column['sortable'] ?? false)) {
                $this->errors[] = new Message('metadata.grid_view.not_sortable', '%path%: "%field%" is a %type% field: it cannot be sorted.', ['path' => $path, 'field' => $field, 'type' => str_replace('_', '-', $object->field($field)->type)]);
            }
            $sortable = false;
        }

        return [
            'key' => $key,
            'field' => \is_string($field) ? $field : null,
            'template' => \is_string($template) ? $template : null,
            'sources' => $sources,
            'label' => $this->label($path, $column['label'] ?? null),
            ...$this->renderer($path, $column['renderer'] ?? null, $column['options'] ?? null),
            'hidden' => $this->flag($path, $column, 'hidden', false),
            'sortable' => $sortable,
            'filter' => $this->flag($path, $column, 'filter', true),
        ];
    }

    /**
     * A field the grid may show: a write-only one (a password) never leaves the server.
     */
    private function readable(ObjectDefinitionDto $object, string $field, string|Message $path): bool
    {
        if (!$object->hasField($field)) {
            $this->errors[] = new Message('metadata.grid_view.field_unknown', '%path%: object "%object%" has no field "%field%".', ['path' => $path, 'object' => $object->name, 'field' => $field]);

            return false;
        }
        if ($this->fieldTypes->isWriteOnly($object->field($field)->type)) {
            $this->errors[] = new Message('metadata.grid_view.field_write_only', '%path%: "%field%" is write-only: it cannot be shown.', ['path' => $path, 'field' => $field]);

            return false;
        }

        return true;
    }

    /**
     * @return array{renderer: string|null, options: array<string, mixed>}
     */
    private function renderer(string|Message $path, mixed $renderer, mixed $options): array
    {
        if (null === $renderer) {
            if (null !== $options) {
                $this->errors[] = new Message('metadata.grid_view.options_without_renderer', '%path%: "options" go with a "renderer".', ['path' => $path]);
            }

            return ['renderer' => null, 'options' => []];
        }
        if (!\is_string($renderer) || !\in_array($renderer, $this->renderers->names(), true)) {
            $this->errors[] = new Message('metadata.grid_view.renderer_invalid', '%path%: "renderer" must be one of %renderers%.', ['path' => $path, 'renderers' => implode(', ', $this->renderers->names())]);

            return ['renderer' => null, 'options' => []];
        }
        $options ??= [];
        if (!\is_array($options) || ([] !== $options && array_is_list($options))) {
            $this->errors[] = new Message('metadata.grid_view.options_invalid', '%path%: "options" must be a map.', ['path' => $path]);

            return ['renderer' => $renderer, 'options' => []];
        }
        /** @var array<string, mixed> $map a map: its keys are strings */
        $map = $options;
        foreach ($this->renderers->optionErrors($renderer, $map) as $error) {
            $this->errors[] = new Message('metadata.grid_view.option_invalid', '%path%: %error%', ['path' => $path, 'error' => $error]);
        }

        return ['renderer' => $renderer, 'options' => $map];
    }

    /**
     * @param array<string, array<string, mixed>> $columns
     *
     * @return array<string, string>
     */
    private function sort(mixed $sort, array $columns): array
    {
        if (!\is_array($sort) || ([] !== $sort && array_is_list($sort))) {
            $this->errors[] = new Message('metadata.grid_view.sort_invalid', '"sort" must be a map of column => asc or desc.');

            return [];
        }
        $compiled = [];
        foreach ($sort as $key => $direction) {
            $sortable = \in_array($key, self::SYSTEM_SORT_COLUMNS, true) || true === ($columns[$key]['sortable'] ?? false);
            if (!$sortable) {
                $this->errors[] = new Message('metadata.grid_view.sort_column_invalid', '"sort": "%column%" is no sortable column of the grid (nor created_at, updated_at).', ['column' => (string) $key]);
                continue;
            }
            if (!\in_array($direction, ['asc', 'desc'], true)) {
                $this->errors[] = new Message('metadata.grid_view.sort_direction_invalid', '"sort": the direction of "%column%" must be asc or desc.', ['column' => (string) $key]);
                continue;
            }
            $compiled[(string) $key] = $direction;
        }

        return $compiled;
    }

    private function pageSize(mixed $pageSize): ?int
    {
        if (null !== $pageSize && (!\is_int($pageSize) || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE)) {
            $this->errors[] = new Message('metadata.grid_view.page_size_invalid', '"page_size" must be an integer from 1 to %max%.', ['max' => self::MAX_PAGE_SIZE]);

            return null;
        }

        return $pageSize;
    }

    private function label(string|Message $path, mixed $label): ?string
    {
        if (null === $label) {
            return null;
        }
        if (!\is_string($label) || '' === trim($label) || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            $this->errors[] = new Message('metadata.grid_view.label_invalid', '%path%: "label" must be a text of 1 to %max% characters.', ['path' => $path, 'max' => self::MAX_LABEL_LENGTH]);

            return null;
        }
        if (null !== $error = $this->texts->errorFor($path, $label)) {
            $this->errors[] = $error;
        }

        return trim($label);
    }

    /**
     * @param array<mixed> $column
     */
    private function flag(string|Message $path, array $column, string $key, bool $default): bool
    {
        $value = $column[$key] ?? $default;
        if (!\is_bool($value)) {
            $this->errors[] = new Message('metadata.grid_view.flag_invalid', '%path%: "%key%" must be true or false.', ['path' => $path, 'key' => $key]);

            return $default;
        }

        return $value;
    }

    /**
     * @param array<mixed> $map
     * @param list<string> $allowed
     */
    private function unknownKeys(string|Message $path, array $map, array $allowed): void
    {
        foreach (array_diff(array_keys($map), $allowed) as $key) {
            $this->errors[] = new Message('metadata.grid_view.unknown_key', '%path%: unknown key "%key%" (allowed: %allowed%).', ['path' => $path, 'key' => (string) $key, 'allowed' => implode(', ', $allowed)]);
        }
    }
}
