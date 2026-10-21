<?php

declare(strict_types=1);

namespace App\Module\Access\Application;

use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\RecordAction;
use App\Module\Metadata\Contract\Event\ObjectDefinitionCreated;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use Fedale\RbacBundle\Contract\ItemStorageInterface;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Fedale\RbacBundle\Enum\AuthItemType;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps the RBAC items the application checks: the four record permissions of every
 * object, the admin permission and the super admin role. Never removes anything.
 */
final class PermissionSynchronizer
{
    public function __construct(
        private readonly RbacManagerInterface $rbac,
        private readonly ItemStorageInterface $items,
        private readonly MetadataRegistryInterface $metadata,
    ) {
    }

    /**
     * Runs in the transaction creating the object: its permissions exist as soon as it does.
     */
    #[AsEventListener]
    public function onObjectCreated(ObjectDefinitionCreated $event): void
    {
        $this->syncObject($event->objectName);
    }

    /**
     * @return list<string> the items created
     */
    public function syncAll(): array
    {
        $created = $this->syncBase();
        foreach (array_keys($this->metadata->allObjects()) as $objectName) {
            $created = [...$created, ...$this->syncObject($objectName)];
        }

        return $created;
    }

    /**
     * @return list<string> the items created
     */
    public function syncBase(): array
    {
        return array_values(array_filter([
            $this->ensure(Permissions::ADMINISTER, AuthItemType::PERMISSION, 'Manage objects, fields, search and tables'),
            $this->ensure(Permissions::SUPER_ADMIN_ROLE, AuthItemType::ROLE, 'Every permission, now and future'),
        ]));
    }

    /**
     * @return list<string> the items created
     */
    public function syncObject(string $objectName): array
    {
        $created = [];
        foreach (RecordAction::cases() as $action) {
            $name = $this->ensure(
                Permissions::record($objectName, $action),
                AuthItemType::PERMISSION,
                \sprintf('%s the records of "%s"', ucfirst($action->value), $objectName),
            );
            if (null !== $name) {
                $created[] = $name;
            }
        }

        return $created;
    }

    private function ensure(string $name, AuthItemType $type, string $description): ?string
    {
        if (null !== $this->items->getItem($name)) {
            return null;
        }
        $this->rbac->addItem($name, $type, $description);

        return $name;
    }
}
