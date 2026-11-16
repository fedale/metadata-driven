<?php

declare(strict_types=1);

namespace App\Module\FieldType\Domain\Type;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\SettingDefinition;
use App\Module\FieldType\Contract\Dto\SettingKind;
use App\Module\FieldType\Contract\Dto\StatusMachine;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A record's state among a fixed list, changed by its transitions only (the Record module's
 * StateGuard and RecordTransitions): read-only in the forms, a new record in the initial state.
 */
final class StatusFieldType extends AbstractFieldType
{
    private const int MAX_VALUE_LENGTH = 100;

    public function name(): string
    {
        return 'status';
    }

    public function label(): string
    {
        return 'Status';
    }

    public function description(): string
    {
        return 'A state among a fixed list, changed by its transitions only, each allowed to some roles.';
    }

    public function settingDefinitions(): array
    {
        return [
            new SettingDefinition('states', SettingKind::OptionList, 'States', 'The stored value and the label shown.', true),
            new SettingDefinition('initial', SettingKind::String, 'Initial state', 'The value of the state a new record starts in. Empty: the first.'),
            new SettingDefinition('transitions', SettingKind::TransitionList, 'Transitions', 'One a line: name | label | the states it leaves, by commas | the state it reaches | the roles that may take it, by commas (none: whoever may update the record).'),
        ];
    }

    public function defaultSettings(): array
    {
        return ['states' => [], 'initial' => null, 'transitions' => []];
    }

    protected function validateKnownSettings(array $settings): array
    {
        $states = $settings['states'];
        if (!\is_array($states) || !array_is_list($states) || [] === $states) {
            return ['Setting "states" must be a non-empty list of {value, label} items.'];
        }
        $errors = [];
        $values = [];
        foreach ($states as $index => $state) {
            $value = \is_array($state) ? ($state['value'] ?? null) : null;
            $label = \is_array($state) ? ($state['label'] ?? null) : null;
            if (!\is_string($value) || '' === $value || \strlen($value) > self::MAX_VALUE_LENGTH) {
                $errors[] = \sprintf('State #%d: "value" must be a non-empty string of at most %d characters.', $index, self::MAX_VALUE_LENGTH);
                continue;
            }
            if (!\is_string($label) || '' === trim($label)) {
                $errors[] = \sprintf('State #%d: "label" must be a non-empty string.', $index);
            }
            if (isset($values[$value])) {
                $errors[] = \sprintf('State #%d: value "%s" is duplicated.', $index, $value);
            }
            $values[$value] = true;
        }
        $initial = $settings['initial'];
        if (null !== $initial && (!\is_string($initial) || !isset($values[$initial]))) {
            $errors[] = 'Setting "initial" must be the value of one of the states.';
        }

        $transitions = $settings['transitions'];
        if (!\is_array($transitions) || !array_is_list($transitions)) {
            return [...$errors, 'Setting "transitions" must be a list of {name, label, from, to, roles} items.'];
        }
        $names = [];
        foreach ($transitions as $index => $transition) {
            if (!\is_array($transition)) {
                $errors[] = \sprintf('Transition #%d: a map of name, label, from, to and roles.', $index);
                continue;
            }
            $name = $transition['name'] ?? null;
            if (!\is_string($name) || 1 !== preg_match('/^[a-z][a-z0-9_]{0,49}$/', $name)) {
                $errors[] = \sprintf('Transition #%d: "name" must be lowercase letters, digits and underscores, starting with a letter.', $index);
            } elseif (isset($names[$name])) {
                $errors[] = \sprintf('Transition #%d: name "%s" is duplicated.', $index, $name);
            } else {
                $names[$name] = true;
            }
            if (!\is_string($transition['label'] ?? null) || '' === trim($transition['label'])) {
                $errors[] = \sprintf('Transition #%d: "label" must be a non-empty string.', $index);
            }
            $from = $transition['from'] ?? null;
            if (!\is_array($from) || [] === $from || [] !== array_filter($from, static fn (mixed $state): bool => !\is_string($state) || !isset($values[$state]))) {
                $errors[] = \sprintf('Transition #%d: "from" must list states of the field, one at least.', $index);
            }
            $to = $transition['to'] ?? null;
            if (!\is_string($to) || !isset($values[$to])) {
                $errors[] = \sprintf('Transition #%d: "to" must be a state of the field.', $index);
            }
            $roles = $transition['roles'] ?? [];
            if (!\is_array($roles) || [] !== array_filter($roles, static fn (mixed $role): bool => !\is_string($role) || 1 !== preg_match('/^ROLE_[A-Z0-9_]+$/', $role))) {
                $errors[] = \sprintf('Transition #%d: "roles" must be role names, e.g. ROLE_CLERK.', $index);
            }
        }

        return $errors;
    }

    public function columnSpec(array $settings): ColumnSpec
    {
        return new ColumnSpec(ColumnType::String, length: self::MAX_VALUE_LENGTH);
    }

    public function denormalize(mixed $value, array $settings): ?string
    {
        return self::toNullableString($value);
    }

    public function constraints(array $settings): array
    {
        return [new Assert\Choice(choices: array_keys(StatusMachine::fromSettings($settings)->states), message: 'The value you selected is not a valid choice.')];
    }

    public function widget(array $settings): Widget
    {
        // A choice no one changes in a form: its transitions do.
        return new Widget(WidgetKind::Choice, ['choices' => StatusMachine::fromSettings($settings)->states, 'display' => 'dropdown', 'locked' => true]);
    }

    public function defaultValue(array $settings): ?string
    {
        $machine = StatusMachine::fromSettings($settings);

        return '' === $machine->initial ? null : $machine->initial;
    }
}
