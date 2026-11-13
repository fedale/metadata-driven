<?php

declare(strict_types=1);

namespace App\Module\Access\Application;

use App\Module\Access\Contract\AccessSetupInterface;
use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\RecordAction;
use App\Module\Metadata\Contract\Event\ObjectDefinitionCreated;
use App\Module\Metadata\Contract\Event\ObjectDefinitionPurged;
use App\Module\Metadata\Contract\Event\ObjectDefinitionRenamed;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use Fedale\RbacBundle\Contract\ItemStorageInterface;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Fedale\RbacBundle\Enum\AuthItemType;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps the RBAC items the application checks: the four record permissions of every
 * object, the admin permission and the super admin role. The permissions of an object follow
 * it: renamed with it (the roles and users holding them keep them), removed when it is purged.
 */
final class PermissionSynchronizer implements AccessSetupInterface
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

    #[AsEventListener]
    public function onObjectRenamed(ObjectDefinitionRenamed $event): void
    {
        foreach (RecordAction::cases() as $action) {
            $old = Permissions::record($event->before->name, $action);
            if (null !== $this->items->getItem($old)) {
                $this->rbac->renameItem($old, Permissions::record($event->newName, $action));
            }
        }
        $this->syncObject($event->newName);
    }

    #[AsEventListener]
    public function onObjectPurged(ObjectDefinitionPurged $event): void
    {
        foreach (RecordAction::cases() as $action) {
            if (null !== $this->items->getItem(Permissions::record($event->object->name, $action))) {
                $this->rbac->removeItem(Permissions::record($event->object->name, $action));
            }
        }
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
            $this->ensure(Permissions::MEMBERS, AuthItemType::PERMISSION, 'Manage the members of the tenant and their roles'),
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
