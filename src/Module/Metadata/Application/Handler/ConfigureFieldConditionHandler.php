<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Handler;

use App\Module\Metadata\Application\Command\ConfigureFieldConditionCommand;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\FieldTypeCatalogInterface;
use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Makes a field apply only while another field (a choice or a yes/no) holds some values.
 * No DDL: the column stays, the record pipeline empties it while the field does not apply.
 */
final class ConfigureFieldConditionHandler
{
    public function __construct(
        private readonly ObjectDefinitionRepository $objects,
        private readonly FieldTypeCatalogInterface $fieldTypes,
        private readonly EventDispatcherInterface $events,
        private readonly TransactionRunner $transactions,
    ) {
    }

    public function __invoke(ConfigureFieldConditionCommand $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $object = $this->objects->findByName($command->objectName)
                ?? throw ObjectDefinitionNotFound::named($command->objectName);

            if (null !== $command->when) {
                $deciding = $object->getField($command->when);
                $allowed = $this->fieldTypes->conditionValues($deciding->getType(), $deciding->getSettings());
                if (null === $allowed) {
                    throw InvalidMetadataDefinition::invalidCondition($command->fieldName, \sprintf('"%s" cannot decide: only a choice or a yes/no field can', $command->when));
                }
                $unknown = array_diff($command->values, $allowed);
                if ([] !== $unknown) {
                    throw InvalidMetadataDefinition::invalidCondition($command->fieldName, \sprintf('"%s" never holds %s (it holds: %s)', $command->when, implode(', ', $unknown), implode(', ', $allowed)));
                }
            }

            $object->setFieldCondition($command->fieldName, $command->when, $command->values);
            $this->objects->save($object);
        });
        $this->events->dispatch(new MetadataChanged($command->objectName));
    }
}
