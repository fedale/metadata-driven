<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * When a field applies: only while another field of the same object (a choice or a yes/no)
 * holds one of some values, e.g. the hours of a qualification when its kind is "course".
 *
 * A field that does not apply is hidden in the forms, not required, and emptied when the
 * record is saved.
 */
final readonly class FieldConditionDto
{
    /**
     * @param string       $field  the field deciding: a select or a boolean of the same object
     * @param list<string> $values the values it must hold; a boolean holds "true" or "false"
     */
    public function __construct(
        public string $field,
        public array $values,
    ) {
    }

    /**
     * @param mixed $value the value of the deciding field, as stored or as typed
     */
    public function isMetBy(mixed $value): bool
    {
        $value = self::normalize($value);

        return null !== $value && \in_array($value, $this->values, true);
    }

    /**
     * The value of a deciding field as the condition compares it: a boolean as "true" or
     * "false", a scalar as a string, anything empty as null.
     */
    public static function normalize(mixed $value): ?string
    {
        return match (true) {
            \is_bool($value) => $value ? 'true' : 'false',
            \is_scalar($value) && '' !== (string) $value => (string) $value,
            default => null,
        };
    }
}
