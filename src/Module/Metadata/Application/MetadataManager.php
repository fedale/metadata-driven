<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application;

use App\Module\Metadata\Application\Command\ConfigureFieldConditionCommand;
use App\Module\Metadata\Application\Command\ConfigureFieldSettingsCommand;
use App\Module\Metadata\Application\Command\ConfigureObjectLabelCommand;
use App\Module\Metadata\Application\Command\ConfigureObjectSearchCommand;
use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Command\DeleteFormCommand;
use App\Module\Metadata\Application\Command\DeleteViewCommand;
use App\Module\Metadata\Application\Command\SaveFormCommand;
use App\Module\Metadata\Application\Command\SaveViewCommand;
use App\Module\Metadata\Application\Handler\ConfigureFieldConditionHandler;
use App\Module\Metadata\Application\Handler\ConfigureFieldSettingsHandler;
use App\Module\Metadata\Application\Handler\ConfigureObjectLabelHandler;
use App\Module\Metadata\Application\Handler\ConfigureObjectSearchHandler;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Metadata\Application\Handler\DeleteFormHandler;
use App\Module\Metadata\Application\Handler\DeleteViewHandler;
use App\Module\Metadata\Application\Handler\SaveFormHandler;
use App\Module\Metadata\Application\Handler\SaveViewHandler;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\Event\MetadataChanged;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Domain\Model\ObjectDefinition;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use App\Shared\Transaction\TransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validates the input with the constraints of the commands, then runs their handlers.
 */
final class MetadataManager implements MetadataManagerInterface
{
    private const int MAX_LABEL_LENGTH = 100;

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly CreateObjectHandler $createObject,
        private readonly CreateFieldHandler $createField,
        private readonly ConfigureObjectLabelHandler $configureLabel,
        private readonly ConfigureObjectSearchHandler $configureSearch,
        private readonly ConfigureFieldConditionHandler $configureCondition,
        private readonly ConfigureFieldSettingsHandler $configureSettings,
        private readonly SaveViewHandler $saveView,
        private readonly DeleteViewHandler $deleteView,
        private readonly SaveFormHandler $saveForm,
        private readonly DeleteFormHandler $deleteForm,
        private readonly ObjectDefinitionRepository $objects,
        private readonly TransactionRunner $transactions,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    public function createObject(string $name, string $labelSingular, string $labelPlural, ?string $icon = null): string
    {
        return ($this->createObject)($this->valid(new CreateObjectCommand($name, $labelSingular, $labelPlural, $icon)));
    }

    public function createField(string $objectName, string $name, string $type, string $label, bool $required = false, bool $unique = false, array $settings = []): string
    {
        return ($this->createField)($this->valid(new CreateFieldCommand($objectName, $name, $type, $label, $required, $unique, $settings)));
    }

    public function relabelObject(string $objectName, string $labelSingular, string $labelPlural): void
    {
        $this->assertLabels(['labelSingular' => $labelSingular, 'labelPlural' => $labelPlural]);
        $this->changeObject($objectName, static fn (ObjectDefinition $object) => $object->relabel($labelSingular, $labelPlural));
    }

    public function relabelField(string $objectName, string $fieldName, string $label): void
    {
        $this->assertLabels(['label' => $label]);
        $this->changeObject($objectName, static fn (ObjectDefinition $object) => $object->getField($fieldName)->relabel($label));
    }

    public function configureFieldSettings(string $objectName, string $fieldName, array $settings): void
    {
        ($this->configureSettings)($this->valid(new ConfigureFieldSettingsCommand($objectName, $fieldName, $settings)));
    }

    public function configureFieldCondition(string $objectName, string $fieldName, ?string $when, array $values = []): void
    {
        ($this->configureCondition)($this->valid(new ConfigureFieldConditionCommand($objectName, $fieldName, $when, $values)));
    }

    public function configureLabel(string $objectName, ?string $field): void
    {
        ($this->configureLabel)($this->valid(new ConfigureObjectLabelCommand($objectName, $field)));
    }

    public function configureSearch(string $objectName, ?string $handler, ?array $fields): void
    {
        ($this->configureSearch)($this->valid(new ConfigureObjectSearchCommand($objectName, $handler, $fields)));
    }

    public function saveView(string $objectName, ViewKind $kind, string $name, string $template, bool $isDefault = false): string
    {
        return ($this->saveView)($this->valid(new SaveViewCommand($objectName, $name, $template, $kind, $isDefault)));
    }

    public function deleteView(string $objectName, ViewKind $kind, string $name): void
    {
        ($this->deleteView)($this->valid(new DeleteViewCommand($objectName, $name, $kind)));
    }

    public function saveForm(string $name, string $definition): string
    {
        return ($this->saveForm)($this->valid(new SaveFormCommand($name, $definition)));
    }

    public function deleteForm(string $name): void
    {
        ($this->deleteForm)($this->valid(new DeleteFormCommand($name)));
    }

    /**
     * @template T of object
     *
     * @param T $command
     *
     * @return T
     */
    private function valid(object $command): object
    {
        $errors = [];
        foreach ($this->validator->validate($command) as $violation) {
            $errors[$violation->getPropertyPath()][] = (string) $violation->getMessage();
        }
        if ([] !== $errors) {
            throw new MetadataInputInvalid($errors);
        }

        return $command;
    }

    /**
     * @param array<string, string> $labels input name => label
     */
    private function assertLabels(array $labels): void
    {
        $errors = [];
        foreach ($labels as $input => $label) {
            foreach ($this->validator->validate($label, [new Assert\NotBlank(), new Assert\Length(max: self::MAX_LABEL_LENGTH)]) as $violation) {
                $errors[$input][] = (string) $violation->getMessage();
            }
        }
        if ([] !== $errors) {
            throw new MetadataInputInvalid($errors);
        }
    }

    /**
     * A change of labels only: no DDL, the cache is dropped once committed.
     *
     * @param callable(ObjectDefinition): void $change
     */
    private function changeObject(string $objectName, callable $change): void
    {
        $this->transactions->run(function () use ($objectName, $change): void {
            $object = $this->objects->findByName($objectName) ?? throw ObjectDefinitionNotFound::named($objectName);
            $change($object);
            $this->objects->save($object);
        });
        $this->events->dispatch(new MetadataChanged($objectName));
    }
}
