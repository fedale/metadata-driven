<?php

declare(strict_types=1);

namespace App\Module\Record\Application\State;

use App\Module\FieldType\Contract\Dto\StatusMachine;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Event\RecordWriteAction;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordEventScopeInterface;

/**
 * A Status changes by its transitions only: a new record starts in the initial state (none
 * given: set to it), an update that changes it is refused unless its transition is under way.
 * Every write but a package's, whatever the events say: a workflow's step too.
 */
final class StateGuard
{
    public const string FIELD_TYPE = 'status';

    public function __construct(
        private readonly TransitionContext $context,
        private readonly RecordEventScopeInterface $scope,
    ) {
    }

    /**
     * @throws RecordValidationFailed
     */
    public function check(ObjectDefinitionDto $object, RecordWriteAction $action, ?string $id, RecordInput $input, ?RecordOutput $current): RecordInput
    {
        if (RecordWriteAction::Delete === $action || $this->scope->isPackageWrite()) {
            return $input;
        }
        $values = $input->values;
        $errors = [];
        foreach ($object->fields as $name => $field) {
            if (self::FIELD_TYPE !== $field->type) {
                continue;
            }
            $machine = StatusMachine::fromSettings($field->settings);
            $given = \array_key_exists($name, $values) ? self::text($values[$name]) : null;
            if (RecordWriteAction::Create === $action) {
                if (null === $given) {
                    $values[$name] = $machine->initial;
                } elseif ($given !== $machine->initial) {
                    $errors[$name][] = \sprintf('A new record starts in the state "%s": its transitions change it.', $machine->states[$machine->initial] ?? $machine->initial);
                }
                continue;
            }
            if (!\array_key_exists($name, $values) || $given === self::text($current?->get($name))) {
                continue;
            }
            if (!$this->context->allows($object->name, $id, $name, $given)) {
                $errors[$name][] = 'Changed by its transitions only.';
            }
        }
        if ([] !== $errors) {
            throw new RecordValidationFailed($object->name, $errors);
        }

        return new RecordInput($object->name, $values);
    }

    private static function text(mixed $value): ?string
    {
        return null === $value || '' === $value ? null : (\is_scalar($value) ? (string) $value : null);
    }
}
