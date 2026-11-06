<?php

declare(strict_types=1);

namespace App\Shared\Csv;

/**
 * Reads a CSV file as spreadsheets write it: a header of names, then one row per line,
 * comma-separated, quotes doubled inside quotes (RFC 4180), UTF-8 with or without a byte order
 * mark. An empty cell is null: no value.
 *
 * App\TemplateFormat reads seeds with its own copy (SeedRecords): the format stays free of the app.
 */
final class CsvFile
{
    /**
     * @return list<array{line: int, values: array<string, string|null>}> the rows, each with its
     *                                                                    line (the header is line 1)
     *
     * @throws \UnexpectedValueException the file cannot be read, has no header, or a line does not
     *                                   fit the header
     */
    public static function read(string $path): array
    {
        $handle = is_file($path) && is_readable($path) ? fopen($path, 'r') : false;
        if (false === $handle) {
            throw new \UnexpectedValueException(\sprintf('The file "%s" cannot be read.', $path));
        }
        try {
            // No escape character: RFC 4180 doubles quotes, it does not escape them.
            $header = fgetcsv($handle, null, ',', '"', '');
            if (!\is_array($header) || [null] === $header) {
                throw new \UnexpectedValueException('The file has no header: its first line names the columns.');
            }
            $header = array_map(static fn (?string $name): string => trim((string) $name), $header);
            $header[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

            $rows = [];
            for ($line = 2; false !== ($row = fgetcsv($handle, null, ',', '"', '')); ++$line) {
                if ([null] === $row) {
                    continue;
                }
                if (\count($row) !== \count($header)) {
                    throw new \UnexpectedValueException(\sprintf('Line %d has %d values, the header %d.', $line, \count($row), \count($header)));
                }
                $values = [];
                foreach ($header as $i => $name) {
                    $values[$name] = '' === $row[$i] ? null : $row[$i];
                }
                $rows[] = ['line' => $line, 'values' => $values];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
