<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Form;

use Symfony\Component\Form\DataTransformerInterface;

/**
 * Edits a list of strings as text, one per line; blank lines are dropped, each line trimmed.
 *
 * @implements DataTransformerInterface<mixed, string>
 */
final class StringListTransformer implements DataTransformerInterface
{
    public function transform(mixed $value): string
    {
        return \is_array($value) ? implode("\n", array_filter($value, \is_string(...))) : '';
    }

    /**
     * @return list<string>
     */
    public function reverseTransform(mixed $value): array
    {
        $lines = array_map(trim(...), preg_split('/\R/', \is_string($value) ? $value : '') ?: []);

        return array_values(array_filter($lines, static fn (string $line): bool => '' !== $line));
    }
}
