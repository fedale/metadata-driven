<?php

declare(strict_types=1);

namespace App\Module\FieldType\Contract\Dto;

/**
 * A Status field's states and transitions, read from its settings (StatusFieldType).
 */
final readonly class StatusMachine
{
    /**
     * @param array<string, string>           $states      value => label, in their order
     * @param array<string, StatusTransition> $transitions by name, in their order
     */
    public function __construct(
        public array $states,
        public string $initial,
        public array $transitions,
    ) {
    }

    /**
     * @param array<string, mixed> $settings validated settings of a status field
     */
    public static function fromSettings(array $settings): self
    {
        $states = [];
        foreach (\is_array($settings['states'] ?? null) ? $settings['states'] : [] as $state) {
            if (\is_array($state) && \is_string($state['value'] ?? null)) {
                $states[$state['value']] = \is_string($state['label'] ?? null) ? $state['label'] : $state['value'];
            }
        }
        $transitions = [];
        foreach (\is_array($settings['transitions'] ?? null) ? $settings['transitions'] : [] as $transition) {
            if (\is_array($transition) && \is_string($transition['name'] ?? null) && \is_string($transition['to'] ?? null)) {
                $transitions[$transition['name']] = new StatusTransition(
                    $transition['name'],
                    \is_string($transition['label'] ?? null) ? $transition['label'] : $transition['name'],
                    array_values(array_filter(\is_array($transition['from'] ?? null) ? $transition['from'] : [], \is_string(...))),
                    $transition['to'],
                    array_values(array_filter(\is_array($transition['roles'] ?? null) ? $transition['roles'] : [], \is_string(...))),
                );
            }
        }
        $initial = \is_string($settings['initial'] ?? null) && isset($states[$settings['initial']]) ? $settings['initial'] : (string) array_key_first($states);

        return new self($states, $initial, $transitions);
    }

    /**
     * The transitions that leave the state.
     *
     * @return list<StatusTransition>
     */
    public function from(?string $state): array
    {
        return array_values(array_filter($this->transitions, static fn (StatusTransition $transition): bool => null !== $state && \in_array($state, $transition->from, true)));
    }
}
