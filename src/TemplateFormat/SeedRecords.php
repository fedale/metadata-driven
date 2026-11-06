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
        $handle = $package->open($source);
        if (null === $handle) {
            throw new InvalidPackage([new Problem($seed->path, 'spec.source', \sprintf('No file "%s" in the package.', $source))]);
        }

        return $this->csv($handle, $seed->path);
    }

    /**
     * Writes records as a CSV file: the header, then a line per record. Values are strings, or
     * null for an empty cell.
     *
     * @param list<string>                     $header  the field names
     * @param list<array<string, string|null>> $records
     */
    public static function toCsv(array $header, array $records): string
    {
        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            throw new \RuntimeException('No temporary stream can be opened.');
        }
        try {
            fputcsv($stream, $header, ',', '"', '');
            foreach ($records as $record) {
                fputcsv($stream, array_map(static fn (string $name): string => $record[$name] ?? '', $header), ',', '"', '');
            }
            rewind($stream);

            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
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
     * @param resource $handle
     *
     * @return list<array<string, mixed>>
     */
    private function csv($handle, string $path): array
    {
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
