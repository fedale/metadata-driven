<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Grid;

use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\RecordSelectionInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The selection the grid's bulk bar sends (gridview-selection): ids[], or all=1 with the grid's
 * query string, which the grid of the object reads again, as for an export.
 */
final class GridRecordSelection implements RecordSelectionInterface
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordGridFactory $grids,
    ) {
    }

    public function selected(string $objectName, ?string $gridName, int $max): array
    {
        $request = $this->requests->getCurrentRequest();
        if (null === $request) {
            return [];
        }
        if ('1' !== $request->query->getString('all')) {
            $ids = array_values(array_filter($request->query->all('ids'), \is_string(...)));

            return \array_slice(array_values(array_unique($ids)), 0, $max + 1);
        }
        $object = $this->metadata->getObject($objectName);
        $view = null === $gridName ? $object->view(ViewKind::Grid) : $object->view(ViewKind::Grid, $gridName);
        $ids = [];
        foreach ($this->grids->create($object, $view)->getExportRows() as $row) {
            $id = $row->getIdentifier();
            if (null !== $id) {
                $ids[] = $id;
            }
            if (\count($ids) > $max) {
                break;
            }
        }

        return $ids;
    }
}
