<?php

declare(strict_types=1);

namespace App\Module\Search\Application;

use App\Module\Metadata\Contract\SearchHandlerCatalogInterface;
use App\Module\Search\Contract\SearchHandlerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every search handler, by name; also the catalog the Metadata module validates names against.
 */
final class SearchHandlerRegistry implements SearchHandlerCatalogInterface
{
    /** @var array<string, SearchHandlerInterface> */
    private array $handlers = [];

    /**
     * @param iterable<SearchHandlerInterface> $handlers
     */
    public function __construct(
        #[AutowireIterator('app.search_handler')]
        iterable $handlers,
    ) {
        foreach ($handlers as $handler) {
            if (isset($this->handlers[$handler->name()])) {
                throw new \LogicException(\sprintf('Search handler "%s" is registered twice.', $handler->name()));
            }
            $this->handlers[$handler->name()] = $handler;
        }
        ksort($this->handlers);
    }

    public function get(string $name): SearchHandlerInterface
    {
        return $this->handlers[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown search handler "%s".', $name));
    }

    public function supports(string $name): bool
    {
        return isset($this->handlers[$name]);
    }

    public function names(): array
    {
        return array_keys($this->handlers);
    }
}
