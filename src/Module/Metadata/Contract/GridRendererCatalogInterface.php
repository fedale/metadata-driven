<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract;

/**
 * The cell renderers a grid view may name ("renderer: truncate"): PHP code registered by
 * name, so that a view stored in the metadata chooses among them instead of carrying code.
 */
interface GridRendererCatalogInterface
{
    /**
     * @return list<string>
     */
    public function names(): array;

    /**
     * @param array<string, mixed> $options
     *
     * @return list<string> what is wrong with the options; empty when they are valid
     */
    public function optionErrors(string $renderer, array $options): array;
}
