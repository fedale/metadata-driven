<?php

declare(strict_types=1);

namespace App\TemplateFormat;

/**
 * The records of a Seed document: written inline (`records`), or in a CSV file of the package
 * (`source`), a header of field names, then one record per line.
 *
 * Values come as written: strings from a CSV (an empty cell is no value), YAML types inline. A
 * field holding several values (a many-to-many field, a list of files) is a YAML list inline,
 * and its values separated by "|" in a CSV cell.
 */
final class SeedRecords
{
    public const string LIST_SEPARATOR = '|';

    /**
     * @return list<array<string, mixed>> the records, each by field name
     *
     * @throws InvalidPackage the CSV file is missing or malformed
     */
    public function of(Package $package, Document $seed): array
    {
        if (\is_array($seed->spec['records'] ?? null)) {
            $records = [];
            foreach ($seed->spec['records'] as $record) {
                $records[] = \is_array($record) ? self::keyed($record) : [];
            }

            return $records;
        }

        $source = \is_string($seed->spec['source'] ?? null) ? $seed->spec['source'] : '';
        $file = $package->file($source);
        if (null === $file) {
            throw new InvalidPackage([new Problem($seed->path, 'spec.source', \sprintf('No file "%s" in the package.', $source))]);
        }

        return $this->csv($file, $seed->path);
    }

    /**
     * The values of a field holding several: a list as written inline, or a CSV cell split on "|".
     *
     * @return list<string>
     */
    public static function list(mixed $value): array
    {
        $values = match (true) {
            \is_array($value) => $value,
            \is_string($value) => explode(self::LIST_SEPARATOR, $value),
            null === $value => [],
            default => [$value],
        };
        $list = [];
        foreach ($values as $item) {
            $item = \is_scalar($item) ? trim((string) $item) : '';
            if ('' !== $item) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function csv(string $file, string $path): array
    {
        $handle = fopen($file, 'r');
        if (false === $handle) {
            throw new InvalidPackage([new Problem($path, 'spec.source', 'The CSV file cannot be read.')]);
        }
        try {
            // No escape character: RFC 4180 doubles quotes, it does not escape them.
            $header = fgetcsv($handle, null, ',', '"', '');
            if (!\is_array($header) || [null] === $header) {
                throw new InvalidPackage([new Problem($path, 'spec.source', 'The CSV file has no header: its first line names the fields.')]);
            }
            $header = array_map(static fn (?string $name): string => trim((string) $name), $header);
            // A byte order mark, as spreadsheets write it, is not part of the first name.
            $header[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

            $records = [];
            $problems = [];
            for ($line = 2; false !== ($row = fgetcsv($handle, null, ',', '"', '')); ++$line) {
                if ([null] === $row) {
                    continue;
                }
                if (\count($row) !== \count($header)) {
                    $problems[] = new Problem($path, 'spec.source', \sprintf('Line %d has %d values, the header %d.', $line, \count($row), \count($header)));
                    continue;
                }
                $record = [];
                foreach ($header as $i => $name) {
                    // An empty cell is no value.
                    $record[$name] = '' === $row[$i] ? null : $row[$i];
                }
                $records[] = $record;
            }
            if ([] !== $problems) {
                throw new InvalidPackage($problems);
            }

            return $records;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<mixed> $map
     *
     * @return array<string, mixed>
     */
    private static function keyed(array $map): array
    {
        $keyed = [];
        foreach ($map as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
