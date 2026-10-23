<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

use App\Module\Metadata\Contract\Exception\FieldDefinitionNotFound;

/**
 * Read-only snapshot of an object definition, shared with other modules.
 */
final readonly class ObjectDefinitionDto
{
    /**
     * @param array<string, FieldDefinitionDto> $fields        keyed by field name, ordered by position
     * @param string|null                       $searchHandler null for the default search handler
     * @param list<string>|null                 $searchFields  null for the default search fields
     * @param string|null                       $labelField    field naming a record (relations, lookups); null for the default
     * @param list<ViewDefinitionDto>           $views         ordered by kind, then name
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $labelSingular,
        public string $labelPlural,
        public string $tableName,
        public ?string $icon,
        public bool $isSystem,
        public bool $isActive,
        public array $fields,
        public ?string $searchHandler = null,
        public ?array $searchFields = null,
        public ?string $labelField = null,
        public array $views = [],
    ) {
    }

    public function hasField(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    /**
     * The view of a kind with that name, or its default view when no name is given.
     */
    public function view(ViewKind $kind, ?string $name = null): ?ViewDefinitionDto
    {
        foreach ($this->views as $view) {
            if ($view->kind === $kind && (null === $name ? $view->isDefault : $view->name === $name)) {
                return $view;
            }
        }

        return null;
    }

    /**
     * @throws FieldDefinitionNotFound
     */
    public function field(string $name): FieldDefinitionDto
    {
        return $this->fields[$name] ?? throw FieldDefinitionNotFound::named($this->name, $name);
    }
}
