<?php

declare(strict_types=1);

namespace App\Module\Record\Application;

use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\OnDelete;
use App\Module\Metadata\Contract\MetadataRegistryInterface;

/**
 * The objects whose records a deletion may reach through cascade relations, so that a page
 * deleting records can require the delete permission on each of them.
 */
final class CascadeTargets
{
    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
    ) {
    }

    /**
     * Every object whose records a deletion of $objectName's records may cascade to,
     * transitively, itself excluded. Decided on the metadata, whether such records exist or not.
     *
     * @return array<string, ObjectDefinitionDto> keyed by object name
     */
    public function of(string $objectName): array
    {
        $targets = [];
        $pending = [$objectName];
        while (null !== $current = array_pop($pending)) {
            foreach ($this->metadata->incomingRelations($current) as $reference) {
                $source = $reference->object;
                if (OnDelete::Cascade === $reference->settings->onDelete && !isset($targets[$source->name])) {
                    $targets[$source->name] = $source;
                    $pending[] = $source->name;
                }
            }
        }
        unset($targets[$objectName]);

        return $targets;
    }
}
