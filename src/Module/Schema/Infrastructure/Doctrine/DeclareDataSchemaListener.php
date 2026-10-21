<?php

declare(strict_types=1);

namespace App\Module\Schema\Infrastructure\Doctrine;

use App\Shared\Database\DataSchema;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Declares the "data" schema in the schema generated from the ORM mapping.
 *
 * Its tables are hidden by the DBAL schema filter, but PostgreSQL schema names are
 * not filtered: without this, every migrations:diff would emit a spurious
 * "CREATE SCHEMA data" in down(). Only schema tools run this listener.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class DeclareDataSchemaListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();
        if (!$schema->hasNamespace(DataSchema::NAME)) {
            // Deprecated in DBAL 4.5 (namespaces will be derived from tables), but still
            // the only way to declare a schema whose tables are filtered out.
            $schema->createNamespace(DataSchema::NAME);
        }
    }
}
