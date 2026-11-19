<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Definition;

/**
 * What DefinitionNames knows while it walks the steps: the object of the trigger's record, the
 * object of each iterator's items, and the visit of each name.
 *
 * @internal
 */
final readonly class NamesContext
{
    /** @var \Closure(string, ?string, string): (string|null) */
    public \Closure $visit;

    /**
     * @param array<string, string>                            $items iterator key => the object of its items
     * @param callable(string, ?string, string): (string|null) $visit
     */
    public function __construct(
        public ?string $trigger,
        public array $items,
        callable $visit,
    ) {
        $this->visit = $visit(...);
    }
}
