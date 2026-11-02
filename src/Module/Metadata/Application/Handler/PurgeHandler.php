<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Handler;

use App\Module\Metadata\Contract\Dto\RelationSettings;
use App\Module\Metadata\Contract\Dto\TranslationKey;
use App\Module\Metadata\Contract\Event\FieldDefinitionPurged;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Event\ObjectDefinitionPurged;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use App\Module\Metadata\Domain\Repository\TranslationRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Forgets an archived object or field for good, its data with it: the definition, its
 * translations and (through the listeners of the purge events) its table or column and its
 * permissions. Only what is archived can be purged; there is no way back.
 */
final class PurgeHandler
{
    public function __construct(
        private readonly ObjectDefinitionRepository $objects,
        private readonly TranslationRepository $translations,
        private readonly MetadataRegistryInterface $metadata,
        private readonly EventDispatcherInterface $events,
        private readonly TransactionRunner $transactions,
    ) {
    }

    /**
     * Refused while a relation field of another object points at it, an archived one included:
     * its foreign key needs the table.
     */
    public function purgeObject(string $objectName): void
    {
        $this->transactions->run(function () use ($objectName): void {
            $object = $this->objects->findByName($objectName);
            $archived = $this->metadata->archivedObjects()[$objectName] ?? null;
            if (null === $object || $object->isActive() || null === $archived) {
                throw InvalidMetadataDefinition::unknownArchivedObject($objectName);
            }
            $usages = [];
            foreach ($this->objects->findAll() as $other) {
                if ($other === $object) {
                    continue;
                }
                foreach ([...$other->getFields(), ...$other->getArchivedFields()] as $field) {
                    if (RelationSettings::FIELD_TYPE === $field->getType() && ($field->getSettings()['target'] ?? null) === $objectName) {
                        $usages[] = \sprintf('relation field "%s.%s"%s points at it', $other->getName(), $field->getName(), $field->isArchived() || !$other->isActive() ? ' (archived)' : '');
                    }
                }
            }
            if ([] !== $usages) {
                throw InvalidMetadataDefinition::stillUsed(\sprintf('Object "%s"', $objectName), 'purged', $usages);
            }

            $this->translations->removeKeysUnder(TranslationKey::objectPrefixes($objectName));
            $this->objects->remove($object);
            $this->events->dispatch(new ObjectDefinitionPurged($archived));
        });
        $this->events->dispatch(new MetadataChanged($objectName));
    }

    public function purgeField(string $objectName, string $fieldName): void
    {
        $this->transactions->run(function () use ($objectName, $fieldName): void {
            $object = $this->objects->findByName($objectName);
            if (null === $object || !$object->isActive()) {
                throw ObjectDefinitionNotFound::named($objectName);
            }
            $before = $this->metadata->getObject($objectName);
            $field = $before->archivedFields[$fieldName] ?? throw InvalidMetadataDefinition::unknownArchivedField($objectName, $fieldName);

            $this->translations->removeKeysUnder(TranslationKey::fieldPrefixes($objectName, $fieldName));
            $object->removeArchivedField($fieldName);
            $this->objects->save($object);
            $this->events->dispatch(new FieldDefinitionPurged($before, $field));
        });
        $this->events->dispatch(new MetadataChanged($objectName));
    }
}
