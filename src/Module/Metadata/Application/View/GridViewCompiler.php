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
 *       - tags                                   # a many-to-many field: never sortable
 *       - { name: fullname, label: Full name, template: '{firstname} {lastname}' }
 *     sort: { lastname: asc }                    # optional: the initial order
 *     page_size: 50                              # optional
 *
 * Every error is collected and reported at once under the "template" input.
 */
final class GridViewCompiler
{
    public const string INPUT = 'template';
    public const array SYSTEM_SORT_COLUMNS = ['created_at', 'updated_at'];
    private const int MAX_LABEL_LENGTH = 100;
    private const int MAX_PAGE_SIZE = 500;
    private const array ROOT_KEYS = ['columns', 'sort', 'page_size'];
    private const array COLUMN_KEYS = ['field', 'name', 'template', 'label', 'renderer', 'options', 'hidden', 'sortable', 'filter'];

    /** @var list<string> */
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
            throw new MetadataInputInvalid([self::INPUT => [\sprintf('Invalid YAML: %s', $e->getMessage())]]);
        }
        if (!\is_array($definition) || array_is_list($definition)) {
            throw new MetadataInputInvalid([self::INPUT => ['The definition must be a map with at least "columns".']]);
        }
        $this->unknownKeys('(grid)', $definition, self::ROOT_KEYS);

        $columns = [];
        $list = $definition['columns'] ?? null;
        if (!\is_array($list) || !array_is_list($list) || [] === $list) {
            $this->errors[] = '"columns" must be a non-empty list.';
            $list = [];
        }
        foreach ($list as $index => $column) {
            $compiled = $this->column($object, $index + 1, \is_string($column) ? ['field' => $column] : $column);
            if (null === $compiled) {
                continue;
            }
            $key = $compiled['key'];
            if (isset($columns[$key])) {
                $this->errors[] = \sprintf('Column "%s" is listed twice.', $key);
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
            $this->errors[] = \sprintf('Column #%d must be a field name or a map.', $position);

            return null;
        }
        $path = \sprintf('Column #%d', $position);
        $this->unknownKeys($path, $column, self::COLUMN_KEYS);

        $field = $column['field'] ?? null;
        $name = $column['name'] ?? null;
        $template = $column['template'] ?? null;
        $sources = [];
        if (null !== $field) {
            if (null !== $name || null !== $template) {
                $this->errors[] = \sprintf('%s: a field column takes "field"; a virtual column takes "name" and "template", not both.', $path);

                return null;
            }
            if (!\is_string($field) || !$this->readable($object, $field, $path)) {
                return null;
            }
            $key = $field;
        } else {
            if (!\is_string($name) || 1 !== preg_match(MachineName::PATTERN, $name) || \strlen($name) > MachineName::MAX_LENGTH) {
                $this->errors[] = \sprintf('%s: give it a "field", or a "name" (lowercase letters, digits and single underscores) and a "template".', $path);

                return null;
            }
            $path = \sprintf('Column "%s"', $name);
            if ($object->hasField($name)) {
                $this->errors[] = \sprintf('%s: "%s" is a field of "%s": a virtual column needs a name of its own.', $path, $name, $object->name);

                return null;
            }
            if (!\is_string($template) || '' === trim($template) || mb_strlen($template) > self::MAX_LABEL_LENGTH * 2) {
                $this->errors[] = \sprintf('%s: "template" must be a text with {field} tokens, at most %d characters.', $path, self::MAX_LABEL_LENGTH * 2);

                return null;
            }
            $sources = array_values(array_unique(ViewDefinitionDto::tokens($template)));
            if ([] === $sources) {
                $this->errors[] = \sprintf('%s: the template places no {field}.', $path);

                return null;
            }
            foreach ($sources as $source) {
                if (!$this->readable($object, $source, $path)) {
                    return null;
                }
                if (null !== $object->field($source)->relation()) {
                    $this->errors[] = \sprintf('%s: {%s} is a relation: show it in a column of its own.', $path, $source);

                    return null;
                }
                if (!$object->field($source)->storesColumn()) {
                    $this->errors[] = \sprintf('%s: {%s} is a many-to-many field: show it in a column of its own.', $path, $source);

                    return null;
                }
            }
            $key = $name;
        }
        // The records a many-to-many field links to have no order of their own.
        $sortable = $this->flag($path, $column, 'sortable', true);
        if (\is_string($field) && !$object->field($field)->storesColumn()) {
            if (true === ($column['sortable'] ?? false)) {
                $this->errors[] = \sprintf('%s: "%s" is a many-to-many field: it cannot be sorted.', $path, $field);
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
    private function readable(ObjectDefinitionDto $object, string $field, string $path): bool
    {
        if (!$object->hasField($field)) {
            $this->errors[] = \sprintf('%s: object "%s" has no field "%s".', $path, $object->name, $field);

            return false;
        }
        if ($this->fieldTypes->isWriteOnly($object->field($field)->type)) {
            $this->errors[] = \sprintf('%s: "%s" is write-only: it cannot be shown.', $path, $field);

            return false;
        }

        return true;
    }

    /**
     * @return array{renderer: string|null, options: array<string, mixed>}
     */
    private function renderer(string $path, mixed $renderer, mixed $options): array
    {
        if (null === $renderer) {
            if (null !== $options) {
                $this->errors[] = \sprintf('%s: "options" go with a "renderer".', $path);
            }

            return ['renderer' => null, 'options' => []];
        }
        if (!\is_string($renderer) || !\in_array($renderer, $this->renderers->names(), true)) {
            $this->errors[] = \sprintf('%s: "renderer" must be one of %s.', $path, implode(', ', $this->renderers->names()));

            return ['renderer' => null, 'options' => []];
        }
        $options ??= [];
        if (!\is_array($options) || ([] !== $options && array_is_list($options))) {
            $this->errors[] = \sprintf('%s: "options" must be a map.', $path);

            return ['renderer' => $renderer, 'options' => []];
        }
        /** @var array<string, mixed> $map a map: its keys are strings */
        $map = $options;
        foreach ($this->renderers->optionErrors($renderer, $map) as $error) {
            $this->errors[] = \sprintf('%s: %s', $path, $error);
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
            $this->errors[] = '"sort" must be a map of column => asc or desc.';

            return [];
        }
        $compiled = [];
        foreach ($sort as $key => $direction) {
            $sortable = \in_array($key, self::SYSTEM_SORT_COLUMNS, true) || true === ($columns[$key]['sortable'] ?? false);
            if (!$sortable) {
                $this->errors[] = \sprintf('"sort": "%s" is no sortable column of the grid (nor created_at, updated_at).', $key);
                continue;
            }
            if (!\in_array($direction, ['asc', 'desc'], true)) {
                $this->errors[] = \sprintf('"sort": the direction of "%s" must be asc or desc.', $key);
                continue;
            }
            $compiled[(string) $key] = $direction;
        }

        return $compiled;
    }

    private function pageSize(mixed $pageSize): ?int
    {
        if (null !== $pageSize && (!\is_int($pageSize) || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE)) {
            $this->errors[] = \sprintf('"page_size" must be an integer from 1 to %d.', self::MAX_PAGE_SIZE);

            return null;
        }

        return $pageSize;
    }

    private function label(string $path, mixed $label): ?string
    {
        if (null === $label) {
            return null;
        }
        if (!\is_string($label) || '' === trim($label) || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            $this->errors[] = \sprintf('%s: "label" must be a text of 1 to %d characters.', $path, self::MAX_LABEL_LENGTH);

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
    private function flag(string $path, array $column, string $key, bool $default): bool
    {
        $value = $column[$key] ?? $default;
        if (!\is_bool($value)) {
            $this->errors[] = \sprintf('%s: "%s" must be true or false.', $path, $key);

            return $default;
        }

        return $value;
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
