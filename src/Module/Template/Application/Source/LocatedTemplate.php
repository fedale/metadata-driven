<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Source;

use App\TemplateFormat\IndexEntry;

/**
 * A version of a template found in an index.
 */
final readonly class LocatedTemplate
{
    public function __construct(
        /** The name of the index: "catalog", or one added with metadata:template:repo. */
        public string $source,
        /** The address of the index: an installation records it, its upgrades look there first. */
        public string $index,
        public IndexEntry $entry,
    ) {
    }
}
