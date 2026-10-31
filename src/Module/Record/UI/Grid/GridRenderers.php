<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Grid;

use App\Module\Metadata\Contract\GridRendererCatalogInterface;
use App\Module\Record\UI\Grid\Renderer\CellRendererInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every cell renderer, by name; also the catalog the Metadata module checks grid views against.
 */
final class GridRenderers implements GridRendererCatalogInterface
{
    /** @var array<string, CellRendererInterface> */
    private array $renderers = [];

    /**
     * @param iterable<CellRendererInterface> $renderers
     */
    public function __construct(
        #[AutowireIterator(CellRendererInterface::TAG)]
        iterable $renderers,
    ) {
        foreach ($renderers as $renderer) {
            if (isset($this->renderers[$renderer->name()])) {
                throw new \LogicException(\sprintf('Cell renderer "%s" is registered twice.', $renderer->name()));
            }
            $this->renderers[$renderer->name()] = $renderer;
        }
        ksort($this->renderers);
    }

    public function get(string $name): CellRendererInterface
    {
        return $this->renderers[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown cell renderer "%s".', $name));
    }

    public function names(): array
    {
        return array_keys($this->renderers);
    }

    public function optionErrors(string $renderer, array $options): array
    {
        return $this->get($renderer)->optionErrors($options);
    }
}
