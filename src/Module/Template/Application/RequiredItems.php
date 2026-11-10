<?php

declare(strict_types=1);

namespace App\Module\Template\Application;

/**
 * The objects and fields of the templates another one requires, as this instance holds them
 * now: their ids (from core.template_item) and their current names. A requiring template names
 * them by id; the app, by name. Resolved through the recorded items, never by name, so a
 * required object renamed since its install keeps every reference to it.
 */
final readonly class RequiredItems
{
    /**
     * @param array<string, string>                $objects object id => name
     * @param array<string, array{string, string}> $fields  field id => [object name, field name]
     */
    public function __construct(
        private array $objects = [],
        private array $fields = [],
    ) {
    }

    public function object(string $id): ?string
    {
        return $this->objects[$id] ?? null;
    }

    public function field(string $id): ?string
    {
        return ($this->fields[$id] ?? null)[1] ?? null;
    }

    /**
     * @return array<string, string> object name => id
     */
    public function objectIds(): array
    {
        return array_flip($this->objects);
    }

    /**
     * @return array<string, array<string, string>> object name => field name => id
     */
    public function fieldIds(): array
    {
        $ids = [];
        foreach ($this->fields as $id => [$object, $field]) {
            $ids[$object][$field] = $id;
        }

        return $ids;
    }
}
