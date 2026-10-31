<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Grid;

use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use Fedale\GridviewBundle\Contract\GridDescriptorProviderInterface;
use Fedale\GridviewBundle\UiSettings\GridDescriptor;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Makes every record grid a scope of the UI settings modal, the grids of the views other than
 * the default included: the grids are built at runtime, so there is no grid controller for the
 * bundle to discover.
 */
final class RecordGridDescriptors implements GridDescriptorProviderInterface
{
    public function __construct(
        // The labels in the user's language.
        #[Target('localizedMetadata')]
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordGridFactory $grids,
    ) {
    }

    public function getGridDescriptors(): iterable
    {
        foreach ($this->metadata->allObjects() as $object) {
            $default = $object->view(ViewKind::Grid);
            yield new GridDescriptor(RecordGridFactory::gridId($object, $default), $object->labelPlural, $this->grids->options($object, $default));
            foreach ($object->views as $view) {
                if (ViewKind::Grid === $view->kind && !$view->isDefault) {
                    yield new GridDescriptor(RecordGridFactory::gridId($object, $view), \sprintf('%s · %s', $object->labelPlural, $view->name), $this->grids->options($object, $view));
                }
            }
        }
    }
}
