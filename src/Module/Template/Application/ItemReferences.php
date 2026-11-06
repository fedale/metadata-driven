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
    /** Never the start of an id: an item of the instance the template does not own. */
    public const string LOCAL = 'local:';

    /**
     * @param array<string, string>                $objects object name => id
     * @param array<string, array<string, string>> $fields  object name => field name => id
     * @param bool                                 $strict  false: an item without id is named "local:<name>"
     *                                                      (the state of an installed template, where the
     *                                                      user may point its items at local ones)
     */
    public function __construct(
        private array $objects,
        private array $fields,
        private bool $strict = true,
    ) {
    }

    public function object(string $name): string
    {
        // An export checked every object pointed at is exported: there, a miss is a bug.
        return $this->objects[$name] ?? ($this->strict ? throw new \LogicException(\sprintf('Object "%s" is not exported.', $name)) : self::LOCAL.$name);
    }

    public function field(string $object, string $name): string
    {
        return $this->fields[$object][$name] ?? ($this->strict ? throw new \LogicException(\sprintf('Field "%s" of "%s" is not exported.', $name, $object)) : self::LOCAL.$object.'.'.$name);
    }
}
