<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Definition;

/**
 * One input of a manual workflow's form, asked before it runs: read as $trigger.input.<name>.
 */
final readonly class FormInput
{
    public const array TYPES = ['text', 'number', 'date', 'boolean', 'select'];

    /**
     * @param list<string> $options a select's choices
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $type,
        public bool $required,
        public array $options = [],
    ) {
    }

    /**
     * An answer as the run reads it: a text, a number, true or false, a date (Y-m-d), one of the
     * options; null when there is none. From a form or a command, where everything is a text.
     *
     * @throws \InvalidArgumentException why the answer will not do
     */
    public function answer(mixed $answer): string|int|float|bool|null
    {
        if ($answer instanceof \DateTimeInterface) {
            $answer = $answer->format('Y-m-d');
        }
        if (null === $answer || '' === $answer) {
            if ($this->required) {
                throw new \InvalidArgumentException('Required.');
            }

            return 'boolean' === $this->type ? false : null;
        }
        if (!\is_scalar($answer)) {
            throw new \InvalidArgumentException('A single value.');
        }
        $text = (string) $answer;

        return match ($this->type) {
            'number' => is_numeric($text) ? (1 === preg_match('/^-?\d+$/', $text) ? (int) $text : (float) $text) : throw new \InvalidArgumentException('A number.'),
            'date' => self::isDate($text) ? $text : throw new \InvalidArgumentException('A date, as 2026-12-31.'),
            'boolean' => $this->bool($answer),
            'select' => \in_array($text, $this->options, true) ? $text : throw new \InvalidArgumentException(\sprintf('One of %s.', implode(', ', $this->options))),
            default => $text,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter(
            ['name' => $this->name, 'label' => $this->label, 'type' => $this->type, 'required' => $this->required ?: null, 'options' => [] === $this->options ? null : $this->options],
            static fn (mixed $value): bool => null !== $value,
        );
    }

    private function bool(mixed $answer): bool
    {
        $value = \is_bool($answer) ? $answer : filter_var($answer, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);
        if (null === $value) {
            throw new \InvalidArgumentException('true or false.');
        }
        if ($this->required && !$value) {
            throw new \InvalidArgumentException('Required.');
        }

        return $value;
    }

    private static function isDate(string $text): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        return false !== $date && $date->format('Y-m-d') === $text;
    }
}
