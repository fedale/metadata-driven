<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract;

use App\Shared\Message\Message;

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
     * @return list<string|Message> what is wrong with the options, to show in the user's language
     *                              (see MessageTranslator); empty when they are valid
     */
    public function optionErrors(string $renderer, array $options): array;
}
