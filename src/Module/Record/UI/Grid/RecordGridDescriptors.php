<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Grid;

use App\Module\Metadata\Contract\MetadataRegistryInterface;
use Fedale\GridviewBundle\Contract\GridDescriptorProviderInterface;
use Fedale\GridviewBundle\UiSettings\GridDescriptor;

/**
 * Makes every record grid a scope of the UI settings modal: the grids are built at
 * runtime, so there is no grid controller for the bundle to discover.
 */
final class RecordGridDescriptors implements GridDescriptorProviderInterface
{
    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordGridFactory $grids,
    ) {
    }

    public function getGridDescriptors(): iterable
    {
        foreach ($this->metadata->allObjects() as $object) {
            yield new GridDescriptor(RecordGridFactory::gridId($object), $object->labelPlural, $this->grids->options($object));
        }
    }
}
