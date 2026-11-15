<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application;

use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Workflow\Application\Definition\Definition;
use App\Module\Workflow\Application\Definition\FormInput;
use App\Module\Workflow\Application\Definition\Step;
use App\Module\Workflow\Application\Definition\StepType;
use App\Module\Workflow\Application\Definition\TriggerEvent;

/**
 * The paths a definition's steps can read, as the variable picker offers them: the trigger's
 * record and its fields, the context of the run, each step's output. Which step may read which
 * output is DefinitionValidator's to say.
 */
final class PathCatalog
{
    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
    ) {
    }

    /**
     * @return array{trigger: list<string>, context: list<string>, steps: array<string, list<string>>} the steps' paths by key
     */
    public function paths(?Definition $definition): array
    {
        $context = ['$user.email', '$user.roles', '$workspace.slug', '$workspace.name', '$now', '$today', '$last'];
        if (null === $definition) {
            return ['trigger' => ['$trigger.object', '$trigger.action', '$trigger.record.id', '$trigger.changed'], 'context' => $context, 'steps' => []];
        }

        $object = $definition->trigger->object;
        $trigger = ['$trigger.object', '$trigger.action', '$trigger.record.id', ...array_map(static fn (string $field): string => '$trigger.record.'.$field, $this->fields($object))];
        if (\in_array($definition->trigger->on, [TriggerEvent::Updated, TriggerEvent::CreatedOrUpdated], true)) {
            $trigger = [...$trigger, '$trigger.before.id', ...array_map(static fn (string $field): string => '$trigger.before.'.$field, $this->fields($object))];
        }
        if ($definition->trigger->isManual()) {
            $trigger = [...$trigger, ...array_map(static fn (FormInput $input): string => '$trigger.input.'.$input->name, $definition->trigger->form)];
        } else {
            $trigger[] = '$trigger.changed';
        }

        $steps = [];
        foreach ($definition->allSteps() as $step) {
            $key = $step->key;
            $itemObject = StepType::Iterator === $step->type ? $this->itemObject($definition, $step) : null;
            $steps[$key] = match ($step->type) {
                StepType::If => [$key.'.branch'],
                StepType::Filter => [$key.'.passed'],
                StepType::Refuse => [],
                StepType::SetValues => array_map(static fn (string $field): string => $key.'.'.$field, array_keys($step->values())),
                StepType::DeleteRecord => [$key.'.id'],
                StepType::SearchRecords => [
                    $key.'.records', $key.'.count', $key.'.total', $key.'.first.id',
                    ...array_map(static fn (string $field): string => $key.'.first.'.$field, $this->fields((string) $step->string('object'))),
                ],
                StepType::Iterator => [
                    $key.'.item',
                    ...(null === $itemObject ? [] : [$key.'.item.id', ...array_map(static fn (string $field): string => $key.'.item.'.$field, $this->fields($itemObject))]),
                    $key.'.index',
                    $key.'.count',
                ],
                default => [
                    $key.'.id',
                    ...array_map(static fn (string $field): string => $key.'.'.$field, $this->fields((string) $step->string('object'))),
                    ...(StepType::UpsertRecord === $step->type ? [$key.'.created'] : []),
                ],
            };
        }

        return ['trigger' => $trigger, 'context' => $context, 'steps' => array_filter($steps, static fn (array $paths): bool => [] !== $paths)];
    }

    /**
     * The object of an iterator's items, when it runs on a search's records.
     */
    private function itemObject(Definition $definition, Step $iterator): ?string
    {
        if (1 !== preg_match('/^\{\{\s*([a-z][a-z0-9_]*)\.records\s*\}\}$/', (string) $iterator->string('items'), $match)) {
            return null;
        }
        foreach ($definition->allSteps() as $step) {
            if ($match[1] === $step->key && StepType::SearchRecords === $step->type) {
                return $step->string('object');
            }
        }

        return null;
    }

    /**
     * The fields of an object the chain holds: every one but the passwords.
     *
     * @return list<string>
     */
    private function fields(string $object): array
    {
        if (!$this->metadata->hasObject($object)) {
            return [];
        }
        $fields = [];
        foreach ($this->metadata->getObject($object)->fields as $name => $field) {
            if ('password' !== $field->type) {
                $fields[] = (string) $name;
            }
        }

        return $fields;
    }
}
