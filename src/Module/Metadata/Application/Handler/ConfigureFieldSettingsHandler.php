<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Handler;

use App\Module\Metadata\Application\Command\ConfigureFieldSettingsCommand;
use App\Module\Metadata\Application\Relation\RelationDependencyChecker;
use App\Module\Metadata\Contract\Dto\RelationSettings;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\FieldTypeCatalogInterface;
use App\Module\Metadata\Contract\FieldValueUsageInterface;
use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use App\Module\Metadata\Domain\Model\ObjectDefinition;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Changes the settings of an existing field. No DDL: the settings that shape the column (or
 * the foreign key) are fixed; the others only change how values are checked and shown.
 *
 * An option of a choice may only be removed when no record holds it and no condition names it.
 */
final class ConfigureFieldSettingsHandler
{
    public function __construct(
        private readonly ObjectDefinitionRepository $objects,
        private readonly FieldTypeCatalogInterface $fieldTypes,
        private readonly FieldValueUsageInterface $usage,
        private readonly RelationDependencyChecker $dependencies,
        private readonly EventDispatcherInterface $events,
        private readonly TransactionRunner $transactions,
    ) {
    }

    public function __invoke(ConfigureFieldSettingsCommand $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $object = $this->objects->findByName($command->objectName)
                ?? throw ObjectDefinitionNotFound::named($command->objectName);
            $field = $object->getField($command->fieldName);
            $type = $field->getType();

            $current = $field->getSettings() + $this->fieldTypes->defaultSettings($type);
            $settings = $this->fieldTypes->resolveSettings($type, $command->settings + $current);

            foreach ($this->fieldTypes->fixedSettings($type) as $key) {
                if (($settings[$key] ?? null) !== ($current[$key] ?? null)) {
                    throw InvalidMetadataDefinition::fixedSetting($command->fieldName, $key);
                }
            }
            if (RelationSettings::FIELD_TYPE === $type) {
                $this->dependencies->assertValid($object, $command->fieldName, RelationSettings::fromSettings($settings));
            }
            $this->assertNoValueIsOrphaned($object, $command->fieldName, $type, $current, $settings);

            $object->changeFieldSettings($command->fieldName, $settings);
            $this->objects->save($object);
        });
        $this->events->dispatch(new MetadataChanged($command->objectName));
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $settings
     */
    private function assertNoValueIsOrphaned(ObjectDefinition $object, string $fieldName, string $type, array $current, array $settings): void
    {
        $removed = array_diff(
            $this->fieldTypes->conditionValues($type, $current) ?? [],
            $this->fieldTypes->conditionValues($type, $settings) ?? [],
        );
        foreach ($removed as $value) {
            foreach ($object->getFields() as $other) {
                $condition = $other->getCondition();
                if (null !== $condition && $condition['field'] === $fieldName && \in_array($value, $condition['values'], true)) {
                    throw InvalidMetadataDefinition::optionStillUsed($fieldName, $value, \sprintf('the condition of "%s" names it', $other->getName()));
                }
            }
            $count = $this->usage->countRecordsHolding($object->getName(), $fieldName, $value);
            if ($count > 0) {
                throw InvalidMetadataDefinition::optionStillUsed($fieldName, $value, \sprintf('%d record(s) hold it', $count));
            }
        }
    }
}
