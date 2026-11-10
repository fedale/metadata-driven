<?php

declare(strict_types=1);

namespace App\Module\Template\Application;

use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Template\Application\Upgrade\Snapshot;
use App\Module\Template\Domain\Model\ItemKind;
use App\Module\Template\Domain\Repository\TemplateInstallationRepository;
use App\Module\Template\Domain\Repository\TemplateItemRepository;
use Fedale\TemplateFormat\Package;

/**
 * The templates a template requires (spec.requires), as installed here: what the format's
 * checker needs to check the references to them, and what the app needs to resolve those
 * references to its own items.
 */
final class RequiredTemplates
{
    public function __construct(
        private readonly TemplateInstallationRepository $installations,
        private readonly TemplateItemRepository $items,
        private readonly MetadataRegistryInterface $metadata,
    ) {
    }

    /**
     * The installed versions of the templates the package requires, as recorded when they were
     * installed or last upgraded (their seeds with the records written). One that is not
     * installed is left out: the checker then reports it as missing.
     *
     * @return list<Package>
     */
    public function installed(Package $package): array
    {
        $installed = [];
        foreach (array_keys(Snapshot::requirementsOf(\is_array($package->manifest->spec['requires'] ?? null) ? $package->manifest->spec['requires'] : [])) as $name) {
            $installation = $this->installations->findByName($name);
            if (null !== $installation) {
                $installed[] = Snapshot::toPackage($installation->getSnapshot());
            }
        }

        return $installed;
    }

    /**
     * The templates an installed template requires, as its installed version says; none when it
     * is not installed.
     *
     * @return list<string>
     */
    public function requiredBy(string $template): array
    {
        $installation = $this->installations->findByName($template);

        return null === $installation ? [] : array_keys(Snapshot::requires($installation->getSnapshot()));
    }

    /**
     * The objects and fields the templates own on this instance now, under their ids.
     *
     * @param list<string> $templates
     */
    public function items(array $templates): RequiredItems
    {
        if ([] === $templates) {
            return new RequiredItems();
        }
        $owned = [];
        foreach ($templates as $template) {
            foreach ($this->items->itemsOf($template) as $item) {
                $owned[$item->getKind()->value][$item->getLocalId()] = $item->getItemId();
            }
        }

        $objects = [];
        $fields = [];
        foreach ($this->metadata->allObjects() as $name => $object) {
            $objectId = $owned[ItemKind::Object->value][$object->id] ?? null;
            if (null === $objectId) {
                continue;
            }
            $objects[$objectId] = $name;
            foreach ($object->fields as $fieldName => $field) {
                $fieldId = $owned[ItemKind::Field->value][$field->id] ?? null;
                if (null !== $fieldId) {
                    $fields[$fieldId] = [$name, $fieldName];
                }
            }
        }

        return new RequiredItems($objects, $fields);
    }
}
