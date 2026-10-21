<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract;

/**
 * Port implemented by the Search module, so that Metadata can validate the search
 * handler of an object without depending on it.
 */
interface SearchHandlerCatalogInterface
{
    public function supports(string $name): bool;

    /**
     * @return list<string>
     */
    public function names(): array;
}
