<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Form;

use Symfony\Component\Form\DataTransformerInterface;

/**
 * A Status field's transitions as lines of text, and back:
 * "send | Send | draft, review | sent | ROLE_CLERK, ROLE_BOSS" (the roles may be left out).
 *
 * @implements DataTransformerInterface<mixed, string>
 */
final class TransitionListTransformer implements DataTransformerInterface
{
    public function transform(mixed $value): string
    {
        if (!\is_array($value)) {
            return '';
        }
        $lines = [];
        foreach ($value as $transition) {
            if (!\is_array($transition)) {
                continue;
            }
            $list = static fn (mixed $items): string => implode(', ', array_filter(\is_array($items) ? $items : [], \is_string(...)));
            $lines[] = rtrim(implode(' | ', [
                \is_string($transition['name'] ?? null) ? $transition['name'] : '',
                \is_string($transition['label'] ?? null) ? $transition['label'] : '',
                $list($transition['from'] ?? []),
                \is_string($transition['to'] ?? null) ? $transition['to'] : '',
                $list($transition['roles'] ?? []),
            ]), ' |');
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array{name: string, label: string, from: list<string>, to: string, roles: list<string>}>
     */
    public function reverseTransform(mixed $value): array
    {
        $transitions = [];
        foreach (preg_split('/\R/', \is_string($value) ? $value : '') ?: [] as $line) {
            if ('' === trim($line)) {
                continue;
            }
            [$name, $label, $from, $to, $roles] = array_map(trim(...), explode('|', $line, 5) + [1 => '', 2 => '', 3 => '', 4 => '']);
            $list = static fn (string $items): array => array_values(array_filter(array_map(trim(...), explode(',', $items)), static fn (string $item): bool => '' !== $item));
            $transitions[] = ['name' => $name, 'label' => '' !== $label ? $label : $name, 'from' => $list($from), 'to' => $to, 'roles' => $list($roles)];
        }

        return $transitions;
    }
}
