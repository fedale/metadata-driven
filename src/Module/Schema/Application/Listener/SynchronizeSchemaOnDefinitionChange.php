<?php

declare(strict_types=1);

namespace App\Module\Schema\Application\Listener;

use App\Module\Metadata\Contract\Event\FieldArchiveChanged;
use App\Module\Metadata\Contract\Event\FieldDefinitionCreated;
use App\Module\Metadata\Contract\Event\FieldDefinitionPurged;
use App\Module\Metadata\Contract\Event\FieldDefinitionRenamed;
use App\Module\Metadata\Contract\Event\ObjectDefinitionCreated;
use App\Module\Metadata\Contract\Event\ObjectDefinitionPurged;
use App\Module\Metadata\Contract\Event\ObjectDefinitionRenamed;
use App\Module\Metadata\Contract\Event\ObjectSearchConfigured;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Applies the DDL inside the transaction persisting the definition: if the DDL
 * fails, the definition is rolled back too (PostgreSQL DDL is transactional).
 */
final class SynchronizeSchemaOnDefinitionChange
{
    public function __construct(
        private readonly SchemaSynchronizerInterface $synchronizer,
        private readonly MetadataRegistryInterface $metadata,
    ) {
    }

    #[AsEventListener]
    public function onObjectCreated(ObjectDefinitionCreated $event): void
    {
        $this->synchronizer->synchronize($event->objectName);
    }

    #[AsEventListener]
    public function onFieldCreated(FieldDefinitionCreated $event): void
    {
        $this->synchronizer->synchronize($event->objectName);
    }

    #[AsEventListener]
    public function onSearchConfigured(ObjectSearchConfigured $event): void
    {
        $this->synchronizer->synchronize($event->objectName);
    }

    #[AsEventListener]
    public function onFieldArchiveChanged(FieldArchiveChanged $event): void
    {
        $this->synchronizer->synchronize($event->objectName);
    }

    #[AsEventListener]
    public function onObjectRenamed(ObjectDefinitionRenamed $event): void
    {
        $this->synchronizer->rename($event->before, $this->metadata->getObject($event->newName));
    }

    #[AsEventListener]
    public function onFieldRenamed(FieldDefinitionRenamed $event): void
    {
        $this->synchronizer->rename($event->before, $this->metadata->getObject($event->before->name));
    }

    #[AsEventListener]
    public function onObjectPurged(ObjectDefinitionPurged $event): void
    {
        $this->synchronizer->dropTable($event->object);
    }

    #[AsEventListener]
    public function onFieldPurged(FieldDefinitionPurged $event): void
    {
        $this->synchronizer->dropColumn($event->object, $event->field);
    }
}
