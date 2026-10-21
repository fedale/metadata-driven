<?php

declare(strict_types=1);

namespace App\Module\Schema\Application\Listener;

use App\Module\Metadata\Contract\Event\FieldDefinitionCreated;
use App\Module\Metadata\Contract\Event\ObjectDefinitionCreated;
use App\Module\Metadata\Contract\Event\ObjectSearchConfigured;
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
}
