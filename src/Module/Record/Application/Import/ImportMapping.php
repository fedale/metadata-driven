<?php

declare(strict_types=1);

namespace App\Module\Record\Application\Import;

/**
 * The columns a user mapped in the import wizard, applied to the rows of their file.
 */
final class ImportMapping
{
    /**
     * The rows with the mapped columns only, named after their fields; one at a time, as they
     * come.
     *
     * @param iterable<array{line: int, values: array<string, string|null>}> $rows
     * @param array<string, string|null>                                     $mapping header => field; null: ignored
     *
     * @return \Generator<int, array{line: int, values: array<string, string|null>}>
     */
    public static function rename(iterable $rows, array $mapping): \Generator
    {
        foreach ($rows as $row) {
            $values = [];
            foreach ($mapping as $header => $field) {
                if (null !== $field) {
                    $values[$field] = $row['values'][$header] ?? null;
                }
            }
            yield ['line' => $row['line'], 'values' => $values];
        }
    }
}
