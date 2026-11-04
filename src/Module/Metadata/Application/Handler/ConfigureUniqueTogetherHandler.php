<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Handler;

use App\Module\Metadata\Application\Command\ConfigureUniqueTogetherCommand;
use App\Module\Metadata\Application\Lifecycle\MetadataUsages;
use App\Module\Metadata\Contract\DuplicateRecordsInterface;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Event\ObjectUniqueTogetherConfigured;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\FieldTypeCatalogInterface;
use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Adds or removes a set of fields unique together; the Schema module builds or drops its
 * unique index in the same transaction.
 */
final class ConfigureUniqueTogetherHandler
{
    public function __construct(
        private readonly ObjectDefinitionRepository $objects,
        private readonly FieldTypeCatalogInterface $fieldTypes,
        private readonly DuplicateRecordsInterface $duplicates,
        private readonly MetadataUsages $usages,
        private readonly EventDispatcherInterface $events,
        private readonly TransactionRunner $transactions,
    ) {
    }

    public function __invoke(ConfigureUniqueTogetherCommand $command): void
    {
        $fields = $command->fields;
        $this->transactions->run(function () use ($command, $fields): void {
            $object = $this->objects->findByName($command->objectName)
                ?? throw ObjectDefinitionNotFound::named($command->objectName);

            if ($command->remove) {
                $object->removeUniqueTogether($fields);
                // A many-to-many field going through the object needs its two fields unique together.
                foreach ($this->usages->throughFields($object->getName()) as [$source, $field, $settings]) {
                    $covered = false;
                    foreach ($object->getUniqueTogether() as $set) {
                        $covered = $covered || (\in_array($settings->throughSource, $set, true) && \in_array($settings->throughTarget, $set, true));
                    }
                    if (!$covered) {
                        throw InvalidMetadataDefinition::stillUsed(\sprintf('The unique set (%s) of "%s"', implode(', ', $fields), $object->getName()), 'removed', [\sprintf('many-to-many field "%s.%s" goes through it', $source->name, $field->name)]);
                    }
                }
            } else {
                foreach ($fields as $name) {
                    if (!$object->hasField($name)) {
                        continue;
                    }
                    $field = $object->getField($name);
                    if ($field->isManyToMany()) {
                        throw InvalidMetadataDefinition::manyToManyField($name, 'unique');
                    }
                    if ($this->fieldTypes->isWriteOnly($field->getType())) {
                        throw InvalidMetadataDefinition::writeOnlyField($name, 'unique');
                    }
                    if (!$this->fieldTypes->supportsUnique($field->getType())) {
                        throw InvalidMetadataDefinition::notUniqueType($name, $field->getType());
                    }
                }
                $object->addUniqueTogether($fields);
                // The records must already follow the rule: the index would refuse them.
                $duplicates = $this->duplicates->count($object->getName(), $fields);
                if ($duplicates > 0) {
                    throw InvalidMetadataDefinition::invalidUniqueTogether($object->getName(), $fields, \sprintf('%d records share their values with another one; change or delete them first', $duplicates));
                }
            }
            $this->objects->save($object);

            $this->events->dispatch(new ObjectUniqueTogetherConfigured($object->getId()->toRfc4122(), $object->getName()));
        });
        $this->events->dispatch(new MetadataChanged($command->objectName));
    }
}
