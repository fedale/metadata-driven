<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Definition;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\RecordAction;
use App\Module\Access\Contract\WorkspaceRolesInterface;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition;

/**
 * Checks what a definition names, when a version is activated: the objects and fields, each
 * path against what the chain holds at that point (the trigger, and the steps run before it on
 * the way there), each role a step acts as against what the person activating may give.
 *
 * The values themselves are checked when they are written, by the record's own validation.
 */
final class DefinitionValidator
{
    /** The roots of the chain that are always there. */
    public const array ROOTS = ['$trigger', '$user', '$workspace', '$last', '$now', '$today'];

    /** Field types a workflow does not write in phase 1: files come from an upload. */
    private const array UNWRITABLE_TYPES = ['file'];

    /** @var array<string, list<string>> */
    private array $errors = [];

    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
        private readonly AccessCheckerInterface $access,
        private readonly WorkspaceRolesInterface $roles,
    ) {
    }

    /**
     * @throws InvalidWorkflowDefinition
     */
    public function validate(Definition $definition): void
    {
        $this->errors = [];
        $trigger = $definition->trigger;
        $object = $this->object($trigger->object, 'trigger.object');
        if (null !== $object) {
            foreach ($trigger->fields as $i => $field) {
                if (!isset($object->fields[$field])) {
                    $this->error('trigger.fields.'.$i, \sprintf('Object "%s" has no field "%s".', $object->name, $field));
                }
            }
        }
        $this->steps($definition->steps, 'steps', $definition, $object, []);

        if ([] !== $this->errors) {
            throw new InvalidWorkflowDefinition($this->errors);
        }
    }

    /**
     * @param list<Step>   $steps
     * @param list<string> $before the keys of the steps run before these, on the way here
     */
    private function steps(array $steps, string $path, Definition $definition, ?ObjectDefinitionDto $trigger, array $before): void
    {
        foreach ($steps as $i => $step) {
            $at = $path.'.'.$i;
            $this->step($step, $at, $definition, $trigger, $before);
            foreach ($step->branches as $b => $branch) {
                if (null !== $branch->when) {
                    $this->condition($branch->when, $at.'.branches.'.$b.'.when', $definition, $trigger, $before);
                }
                $this->steps($branch->steps, $at.'.branches.'.$b.'.steps', $definition, $trigger, $before);
            }
            $before[] = $step->key;
        }
    }

    /**
     * @param list<string> $before
     */
    private function step(Step $step, string $path, Definition $definition, ?ObjectDefinitionDto $trigger, array $before): void
    {
        if (null !== $step->when) {
            $this->condition($step->when, $path.'.when', $definition, $trigger, $before);
        }
        $target = match ($step->type) {
            StepType::SetValues => $trigger,
            default => null === $step->string('object') ? null : $this->object((string) $step->string('object'), $path.'.object'),
        };
        foreach ($step->values() as $field => $value) {
            if (null !== $target) {
                $definitionOfField = $target->fields[$field] ?? null;
                if (null === $definitionOfField) {
                    $this->error($path.'.values.'.$field, \sprintf('Object "%s" has no field "%s".', $target->name, $field));
                } elseif (\in_array($definitionOfField->type, self::UNWRITABLE_TYPES, true)) {
                    $this->error($path.'.values.'.$field, \sprintf('A workflow does not write a field of type %s.', $definitionOfField->type));
                }
            }
            $this->paths($value, $path.'.values.'.$field, $definition, $trigger, $before);
        }
        foreach (['id', 'message'] as $option) {
            if (null !== $step->string($option)) {
                $this->paths($step->string($option), $path.'.'.$option, $definition, $trigger, $before);
            }
        }
        if (StepType::UpsertRecord === $step->type && null !== $target && null !== $match = $step->string('match')) {
            if (true !== ($target->fields[$match] ?? null)?->isUnique) {
                $this->error($path.'.match', \sprintf('A unique field of "%s": the one that tells the record to update.', $target->name));
            }
            if (!\array_key_exists($match, $step->values())) {
                $this->error($path.'.values', \sprintf('The value of "%s", the field it matches on, is required.', $match));
            }
        }
        if (StepType::SetValues === $step->type && null !== $trigger) {
            // It changes what someone else writes: the person activating must hold that write too.
            $actions = match ($definition->trigger->on) {
                TriggerEvent::Created => [RecordAction::Create],
                TriggerEvent::Updated => [RecordAction::Update],
                TriggerEvent::CreatedOrUpdated => [RecordAction::Create, RecordAction::Update],
                TriggerEvent::Deleted => [],
            };
            if (TriggerEvent::Deleted === $definition->trigger->on) {
                $this->error($path.'.type', 'set_values changes the values being written: a delete writes none.');
            }
            foreach ($actions as $action) {
                $permission = Permissions::record($trigger->name, $action);
                if (!$this->access->holds($permission)) {
                    $this->error($path.'.type', \sprintf('You do not hold %s: a workflow of yours may not change what others write there.', $permission));
                }
            }
        }
        if (null === $step->actAs() && $step->type->writes() && null !== $target) {
            // As whoever triggered the event: the person activating must hold it too, or they
            // would write through the users who do (no way to more power).
            foreach (self::actions($step->type) as $action) {
                $permission = Permissions::record($target->name, $action);
                if (!$this->access->holds($permission)) {
                    $this->error($path.'.type', \sprintf('You do not hold %s: a step acting as whoever triggered the event may not do what you cannot. Give it an act_as, a role you may give.', $permission));
                }
            }
        }
        if (null !== $role = $step->actAs()) {
            if (!\array_key_exists($role, $this->roles->roles())) {
                $this->error($path.'.act_as', \sprintf('"%s" is no role of this workspace.', $role));
            } elseif (!$this->access->canGrant($role)) {
                // No way to more power: acting as a role is giving it to the workflow.
                $this->error($path.'.act_as', \sprintf('You cannot give the role %s: a workflow you activate may not act as it.', $role));
            }
        }
    }

    /**
     * @param list<string> $before
     */
    private function condition(Condition $condition, string $path, Definition $definition, ?ObjectDefinitionDto $trigger, array $before): void
    {
        foreach ($condition->conditions as $i => $nested) {
            $this->condition($nested, $path.'.'.$condition->group.'.'.$i, $definition, $trigger, $before);
        }
        if (null !== $condition->path) {
            $this->path($condition->path, $path.'.field', $definition, $trigger, $before);
            $this->paths($condition->value, $path.'.value', $definition, $trigger, $before);
        }
    }

    /**
     * Checks the {{ … }} of a value, nested in lists and maps.
     *
     * @param list<string> $before
     */
    private function paths(mixed $value, string $path, Definition $definition, ?ObjectDefinitionDto $trigger, array $before): void
    {
        if (\is_array($value)) {
            foreach ($value as $key => $nested) {
                $this->paths($nested, $path.'.'.$key, $definition, $trigger, $before);
            }

            return;
        }
        if (\is_string($value) && preg_match_all(DefinitionParser::TEMPLATE, $value, $matches) > 0) {
            foreach ($matches[1] as $found) {
                $this->path($found, $path, $definition, $trigger, $before);
            }
        }
    }

    /**
     * @param list<string> $before
     */
    private function path(string $path, string $at, Definition $definition, ?ObjectDefinitionDto $trigger, array $before): void
    {
        $segments = explode('.', $path);
        $root = $segments[0];
        if (!\in_array($root, self::ROOTS, true) && !\in_array($root, $before, true)) {
            $this->error($at, \in_array($root, array_map(static fn (Step $step): string => $step->key, $definition->allSteps()), true)
                ? \sprintf('"%s" is not run before this point on every way here: its output cannot be read.', $root)
                : \sprintf('"%s" is no step and no root of the chain (%s).', $root, implode(', ', self::ROOTS)));

            return;
        }
        if ('$trigger' !== $root || !isset($segments[1])) {
            return;
        }
        if (!\in_array($segments[1], ['object', 'action', 'record', 'before', 'changed'], true)) {
            $this->error($at, \sprintf('$trigger holds object, action, record, before and changed: not "%s".', $segments[1]));

            return;
        }
        if ('before' === $segments[1] && !\in_array($definition->trigger->on, [TriggerEvent::Updated, TriggerEvent::CreatedOrUpdated], true)) {
            $this->error($at, '$trigger.before is the record as it was before an update: not there for this trigger.');
        }
        $field = $segments[2] ?? null;
        if (\in_array($segments[1], ['record', 'before'], true) && null !== $field && 'id' !== $field && null !== $trigger && !isset($trigger->fields[$field])) {
            $this->error($at, \sprintf('Object "%s" has no field "%s".', $trigger->name, $field));
        }
    }

    /**
     * The record actions a step that writes needs: an upsert creates or updates.
     *
     * @return list<RecordAction>
     */
    private static function actions(StepType $type): array
    {
        return match ($type) {
            StepType::CreateRecord => [RecordAction::Create],
            StepType::UpdateRecord => [RecordAction::Update],
            StepType::DeleteRecord => [RecordAction::Delete],
            StepType::UpsertRecord => [RecordAction::Create, RecordAction::Update],
            default => [],
        };
    }

    private function object(string $name, string $path): ?ObjectDefinitionDto
    {
        if (!$this->metadata->hasObject($name)) {
            $this->error($path, \sprintf('There is no object "%s".', $name));

            return null;
        }

        return $this->metadata->getObject($name);
    }

    private function error(string $path, string $message): void
    {
        $this->errors[$path][] = $message;
    }
}
