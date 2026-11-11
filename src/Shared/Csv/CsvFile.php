<?php

declare(strict_types=1);

namespace App\Shared\Csv;

/**
 * Reads a CSV file as spreadsheets write it: a header of names, then one row per line,
 * separated by commas (or semicolons, as Excel writes them in Italian), quotes doubled inside
 * quotes (RFC 4180), UTF-8 with or without a byte order mark. An empty cell is null: no value.
 *
 * Fedale\TemplateFormat reads seeds with its own copy (SeedRecords): the format stays free of the app.
 */
final class CsvFile
{
    public const string COMMA = ',';
    public const string SEMICOLON = ';';

    /**
     * @return list<array{line: int, values: array<string, string|null>}> the rows, each with its
     *                                                                    line (the header is line 1)
     *
     * @throws \UnexpectedValueException the file cannot be read, has no header, repeats a header, is
     *                                   not UTF-8, or a line does not fit the header
     */
    public static function read(string $path, string $separator = self::COMMA): array
    {
        if (!\in_array($separator, [self::COMMA, self::SEMICOLON], true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is no CSV separator: use a comma or a semicolon.', $separator));
        }
        $handle = self::open($path);
        try {
            // No escape character: RFC 4180 doubles quotes, it does not escape them.
            $header = fgetcsv($handle, null, $separator, '"', '');
            if (!\is_array($header) || [null] === $header) {
                throw new \UnexpectedValueException('The file has no header: its first line names the columns.');
            }
            self::checkEncoding($header, 1);
            $header = array_map(static fn (?string $name): string => trim((string) $name), $header);
            $header[0] = self::withoutBom($header[0]);
            // The rows are keyed by header: two columns of one name would overwrite each other.
            foreach (array_diff_key($header, array_unique($header)) as $i => $name) {
                throw new \UnexpectedValueException(\sprintf('Column %d repeats the header "%s": give every column a name of its own, or remove it.', $i + 1, $name));
            }

            $rows = [];
            for ($line = 2; false !== ($row = fgetcsv($handle, null, $separator, '"', '')); ++$line) {
                if ([null] === $row) {
                    continue;
                }
                if (\count($row) !== \count($header)) {
                    throw new \UnexpectedValueException(\sprintf('Line %d has %d values, the header %d.', $line, \count($row), \count($header)));
                }
                self::checkEncoding($row, $line);
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

    /**
     * The separator of a file: a semicolon when its header line holds more semicolons than
     * commas, outside quotes; a comma otherwise.
     *
     * @throws \UnexpectedValueException the file cannot be read
     */
    public static function detectSeparator(string $path): string
    {
        $handle = self::open($path);
        try {
            $line = fgets($handle);
        } finally {
            fclose($handle);
        }
        // Quoted text may hold either separator: only what is outside the quotes counts.
        $outside = (string) preg_replace('/"(?:[^"]|"")*"/', '', self::withoutBom(false === $line ? '' : $line));

        return substr_count($outside, self::SEMICOLON) > substr_count($outside, self::COMMA) ? self::SEMICOLON : self::COMMA;
    }

    /**
     * @return resource
     */
    private static function open(string $path)
    {
        $handle = is_file($path) && is_readable($path) ? fopen($path, 'r') : false;
        if (false === $handle) {
            throw new \UnexpectedValueException(\sprintf('The file "%s" cannot be read.', $path));
        }

        return $handle;
    }

    /**
     * @param array<int, string|null> $cells
     */
    private static function checkEncoding(array $cells, int $line): void
    {
        if (!mb_check_encoding(implode('', array_map(strval(...), $cells)), 'UTF-8')) {
            throw new \UnexpectedValueException(\sprintf('Line %d is not UTF-8: save the file as "CSV UTF-8".', $line));
        }
    }

    private static function withoutBom(string $text): string
    {
        return (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);
    }
}
