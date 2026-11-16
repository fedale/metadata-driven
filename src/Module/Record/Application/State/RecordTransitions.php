<?php

declare(strict_types=1);

namespace App\Module\Record\Application\State;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\RecordAction;
use App\Module\FieldType\Contract\Dto\StatusMachine;
use App\Module\FieldType\Contract\Dto\StatusTransition;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Dto\TransitionOption;
use App\Module\Record\Contract\Exception\RecordNotFound;
use App\Module\Record\Contract\Exception\TransitionRefused;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Record\Contract\RecordTransitionsInterface;

final class RecordTransitions implements RecordTransitionsInterface
{
    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordFinderInterface $finder,
        private readonly RecordManagerInterface $records,
        private readonly AccessCheckerInterface $access,
        private readonly TransitionContext $context,
    ) {
    }

    public function available(string $objectName, RecordOutput $record): array
    {
        if (!$this->access->canRecords($objectName, RecordAction::Update)) {
            return [];
        }
        $options = [];
        foreach ($this->metadata->getObject($objectName)->fields as $name => $field) {
            if (StateGuard::FIELD_TYPE !== $field->type) {
                continue;
            }
            $state = $record->get($name);
            foreach (StatusMachine::fromSettings($field->settings)->from(\is_scalar($state) ? (string) $state : null) as $transition) {
                if ($this->hasARole($transition)) {
                    $options[] = new TransitionOption((string) $name, $transition->name, $transition->label, $transition->to);
                }
            }
        }

        return $options;
    }

    public function apply(string $objectName, string $recordId, string $field, string $transition, bool $asRole = false): RecordOutput
    {
        $object = $this->metadata->getObject($objectName);
        $definition = $object->fields[$field] ?? null;
        if (null === $definition || StateGuard::FIELD_TYPE !== $definition->type) {
            throw new TransitionRefused(\sprintf('"%s" is no status field of "%s".', $field, $objectName));
        }
        $machine = StatusMachine::fromSettings($definition->settings);
        $taken = $machine->transitions[$transition] ?? throw new TransitionRefused(\sprintf('The status "%s" has no transition "%s".', $field, $transition));
        $record = $this->finder->find($objectName, $recordId) ?? throw RecordNotFound::withId($objectName, $recordId);
        $state = $record->get($field);
        $state = \is_scalar($state) ? (string) $state : null;
        if (!\in_array($state, $taken->from, true)) {
            throw new TransitionRefused(\sprintf('"%s" is not taken from "%s".', $taken->label, $machine->states[$state ?? ''] ?? (string) $state));
        }
        if (!$asRole && (!$this->access->canRecords($objectName, RecordAction::Update) || !$this->hasARole($taken))) {
            throw new TransitionRefused(\sprintf('You may not take "%s".', $taken->label));
        }

        return $this->context->run($objectName, $recordId, $field, $taken->to, fn (): RecordOutput => $this->records->update($recordId, new RecordInput($objectName, [$field => $taken->to])));
    }

    /**
     * Whether the current user holds one of its roles; none listed, anyone may.
     */
    private function hasARole(StatusTransition $transition): bool
    {
        return [] === $transition->roles || [] !== array_filter($transition->roles, $this->access->holds(...));
    }
}
