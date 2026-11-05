<?php

declare(strict_types=1);

namespace App\Module\Template\Application;

/**
 * The ids of the exported objects and fields, looked up by the names the app uses.
 *
 * @internal used by TemplateExporter
 */
final readonly class ItemReferences
{
    /**
     * @param array<string, string>                $objects object name => id
     * @param array<string, array<string, string>> $fields  object name => field name => id
     */
    public function __construct(
        private array $objects,
        private array $fields,
    ) {
    }

    public function object(string $name): string
    {
        // The export checked every object pointed at is exported: a miss is a bug.
        return $this->objects[$name] ?? throw new \LogicException(\sprintf('Object "%s" is not exported.', $name));
    }

    public function field(string $object, string $name): string
    {
        return $this->fields[$object][$name] ?? throw new \LogicException(\sprintf('Field "%s" of "%s" is not exported.', $name, $object));
    }
}
