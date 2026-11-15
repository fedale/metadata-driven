<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

use App\Module\Workflow\Application\Definition\DefinitionParser;

/**
 * The data of one run: $trigger, $user, $workspace, $now, $today, $last, and each step's output
 * under its key. Read by paths, as {{ … }} in the options of the steps: a value that is one path
 * alone takes the path's value as it is (a number, a list, null); a path inside a text is
 * written as text. Nothing else: no expression, no function.
 */
final class DataChain
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private array $data,
    ) {
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * The value at a path; null where there is none.
     */
    public function get(string $path): mixed
    {
        $value = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * A value of the definition with its {{ … }} resolved, in lists and maps too.
     */
    public function render(mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map($this->render(...), $value);
        }
        if (!\is_string($value)) {
            return $value;
        }
        if (1 === preg_match('/^\s*\{\{\s*('.DefinitionParser::PATH.')\s*\}\}\s*$/', $value, $match)) {
            return $this->get($match[1]);
        }

        return (string) preg_replace_callback(DefinitionParser::TEMPLATE, fn (array $match): string => self::written($this->get($match[1])), $value);
    }

    /**
     * Values by field name, each rendered.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public function values(array $values): array
    {
        return array_map($this->render(...), $values);
    }

    /**
     * A text of the definition, its {{ … }} resolved, as text.
     */
    public function text(?string $text): string
    {
        return null === $text ? '' : self::written($this->render($text));
    }

    /**
     * How a value is written in a text: a list or a map as JSON.
     */
    public static function asText(mixed $value): string
    {
        return self::written($value);
    }

    private static function written(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_scalar($value) => (string) $value,
            default => (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
        };
    }
}
