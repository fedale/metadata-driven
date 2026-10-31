<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * A column of a grid view: a field of the object, or a virtual column joining several fields
 * through a template ("{firstname} {lastname}").
 */
final readonly class GridColumnDto
{
    /**
     * @param string               $key      the field name, or the name of the virtual column
     * @param string|null          $field    the field shown; null for a virtual column
     * @param string|null          $template plain text with a {field} token per source; null for a field column
     * @param list<string>         $sources  the fields of the template, in order: it sorts and filters on them
     * @param string|null          $label    null: the label of the field
     * @param string|null          $renderer the name of a cell renderer; null: the rendering of the field type
     * @param array<string, mixed> $options  the options of the renderer
     * @param bool                 $hidden   off until the user turns it on (the grid's "Columns" menu)
     */
    public function __construct(
        public string $key,
        public ?string $field,
        public ?string $template,
        public array $sources,
        public ?string $label,
        public ?string $renderer,
        public array $options,
        public bool $hidden,
        public bool $sortable,
        public bool $filter,
    ) {
    }

    public function isVirtual(): bool
    {
        return null === $this->field;
    }
}
