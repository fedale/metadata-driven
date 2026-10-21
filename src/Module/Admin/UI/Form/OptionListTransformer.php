<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Form;

use Symfony\Component\Form\DataTransformerInterface;

/**
 * Edits a list of {value, label} options as text, one option per line: "value | label".
 * A line without "|" uses the same text as value and label.
 *
 * @implements DataTransformerInterface<mixed, string>
 */
final class OptionListTransformer implements DataTransformerInterface
{
    public function transform(mixed $value): string
    {
        if (!\is_array($value)) {
            return '';
        }

        $lines = [];
        foreach ($value as $option) {
            if (\is_array($option) && \is_string($option['value'] ?? null) && \is_string($option['label'] ?? null)) {
                $lines[] = $option['value'] === $option['label'] ? $option['value'] : $option['value'].' | '.$option['label'];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function reverseTransform(mixed $value): array
    {
        $options = [];
        foreach (preg_split('/\R/', \is_string($value) ? $value : '') ?: [] as $line) {
            if ('' === trim($line)) {
                continue;
            }
            [$optionValue, $label] = array_map(trim(...), explode('|', $line, 2) + [1 => '']);
            $options[] = ['value' => $optionValue, 'label' => '' !== $label ? $label : $optionValue];
        }

        return $options;
    }
}
