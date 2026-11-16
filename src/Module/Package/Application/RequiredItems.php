<?php

declare(strict_types=1);

namespace App\Module\Package\Application;

/**
 * The objects, fields and workflows of the packages another one requires, as this instance holds them
 * now: their ids (from core.package_item) and their current names. A requiring package names
 * them by id; the app, by name. Resolved through the recorded items, never by name, so a
 * required object renamed since its install keeps every reference to it.
 */
final readonly class RequiredItems
{
    /**
     * @param array<string, string>                $objects   object id => name
     * @param array<string, array{string, string}> $fields    field id => [object name, field name]
     * @param array<string, string>                $workflows workflow id => name
     */
    public function __construct(
        private array $objects = [],
        private array $fields = [],
        private array $workflows = [],
    ) {
    }

    public function workflow(string $id): ?string
    {
        return $this->workflows[$id] ?? null;
    }

    /**
     * @return array<string, string> workflow name => id
     */
    public function workflowIds(): array
    {
        return array_flip($this->workflows);
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
