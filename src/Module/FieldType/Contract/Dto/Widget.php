<?php

declare(strict_types=1);

namespace App\Module\FieldType\Contract\Dto;

/**
 * Framework-free description of the input control for a field.
 *
 * Known options: "maxLength" (int) for text widgets, "scale" (int) for decimals,
 * "choices" (array<string, string>, value => label) for choices, "target" (string) for relations,
 * "min" / "max" (int) for numbers, "display" (string) for a checkbox ("checkbox" or "switch") or a
 * choice ("dropdown" or "radio"), "confirm", "strengthMeter", "revealToggle" (bool) and "minLength" (int) for a
 * password.
 */
final readonly class Widget
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public WidgetKind $kind,
        public array $options = [],
    ) {
    }

    /**
     * The "choices" option, typed; empty for widgets without choices.
     *
     * @return array<string, string> stored value => label
     */
    public function choices(): array
    {
        $choices = [];
        foreach (\is_array($this->options['choices'] ?? null) ? $this->options['choices'] : [] as $value => $label) {
            if (\is_string($label)) {
                $choices[(string) $value] = $label;
            }
        }

        return $choices;
    }
}
