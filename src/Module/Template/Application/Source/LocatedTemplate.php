<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Source;

use Fedale\TemplateFormat\IndexEntry;

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
        /** The key the index's entries are signed with; null for an index accepted unsigned. */
        public ?string $publicKey,
    ) {
    }
}
