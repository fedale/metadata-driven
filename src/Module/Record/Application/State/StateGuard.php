<?php

declare(strict_types=1);

namespace App\Module\Record\Application\State;

use App\Module\FieldType\Contract\Dto\StatusMachine;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Event\RecordWriteAction;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordEventScopeInterface;
use App\Module\Record\Domain\Repository\RecordStore;
use App\Shared\Message\Message;

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
        private readonly RecordStore $store,
    ) {
    }

    /**
     * Run in the write's transaction, on the values as they will be stored.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed> the values, a new record's status set to the initial state
     *
     * @throws RecordValidationFailed
     */
    public function check(ObjectDefinitionDto $object, RecordWriteAction $action, ?string $id, array $values, ?RecordOutput $current): array
    {
        if (RecordWriteAction::Delete === $action || $this->scope->isPackageWrite()) {
            return $values;
        }
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
                $errors[$name][] = new Message('record.state.transitions_only', 'Changed by its transitions only.');
                continue;
            }
            // Its state read again, locked until the write commits: two transitions at once,
            // the second finds the state the first left, and is refused.
            $transition = $this->context->of($object->name, $id);
            $now = null === $id ? null : self::text($this->store->lockedValue($object, $id, $field));
            if (null !== $transition && $now !== $transition->from) {
                $errors[$name][] = new Message('record.state.changed_meanwhile', 'Its state changed meanwhile: try again.');
            }
        }
        if ([] !== $errors) {
            throw new RecordValidationFailed($object->name, $errors);
        }

        return $values;
    }

    private static function text(mixed $value): ?string
    {
        return null === $value || '' === $value ? null : (\is_scalar($value) ? (string) $value : null);
    }
}
