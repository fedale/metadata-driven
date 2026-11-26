<?php

declare(strict_types=1);

namespace App\Shared\Csv;

use App\Shared\Message\Message;

/**
 * Reads a CSV file as spreadsheets write it: a header of names, then one row per line,
 * separated by commas (or semicolons, as Excel writes them in Italian), quotes doubled inside
 * quotes (RFC 4180), UTF-8 with or without a byte order mark. An empty cell is null: no value.
 *
 * Fedale\PackageFormat reads seeds with its own copy (SeedRecords): the format stays free of the app.
 */
final class CsvFile
{
    public const string COMMA = ',';
    public const string SEMICOLON = ';';

    /**
     * @return list<array{line: int, values: array<string, string|null>}> the rows, each with its
     *                                                                    line (the header is line 1)
     *
     * @throws CsvFileUnreadable the file cannot be read, has no header, repeats a header, is
     *                           not UTF-8, or a line does not fit the header
     */
    public static function read(string $path, string $separator = self::COMMA): array
    {
        return iterator_to_array(self::rows($path, $separator), false);
    }

    /**
     * The rows one at a time, for files too large to hold: the file stays open until the last
     * row is read or the generator is dropped. A line that does not fit throws when it is reached,
     * after the rows before it.
     *
     * @return \Generator<int, array{line: int, values: array<string, string|null>}>
     *
     * @throws CsvFileUnreadable as read()
     */
    public static function rows(string $path, string $separator = self::COMMA): \Generator
    {
        self::checkSeparator($separator);
        $handle = self::open($path);
        try {
            $header = self::readHeader($handle, $separator);
            // No escape character: RFC 4180 doubles quotes, it does not escape them.
            for ($line = 2; false !== ($row = fgetcsv($handle, null, $separator, '"', '')); ++$line) {
                if ([null] === $row) {
                    continue;
                }
                if (\count($row) !== \count($header)) {
                    throw new CsvFileUnreadable(new Message('csv.line_does_not_fit', 'Line %line% has %values% values, the header %columns%.', ['line' => $line, 'values' => \count($row), 'columns' => \count($header)]));
                }
                self::checkEncoding($row, $line);
                $values = [];
                foreach ($header as $i => $name) {
                    $values[$name] = '' === $row[$i] ? null : $row[$i];
                }
                yield ['line' => $line, 'values' => $values];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * The names of the columns, the first line of the file.
     *
     * @return list<string>
     *
     * @throws CsvFileUnreadable the file cannot be read, has no header, repeats a header or
     *                           is not UTF-8
     */
    public static function header(string $path, string $separator = self::COMMA): array
    {
        self::checkSeparator($separator);
        $handle = self::open($path);
        try {
            return self::readHeader($handle, $separator);
        } finally {
            fclose($handle);
        }
    }

    /**
     * The separator of a file: a semicolon when its header line holds more semicolons than
     * commas, outside quotes; a comma otherwise.
     *
     * @throws CsvFileUnreadable the file cannot be read
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

    private static function checkSeparator(string $separator): void
    {
        if (!\in_array($separator, [self::COMMA, self::SEMICOLON], true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is no CSV separator: use a comma or a semicolon.', $separator));
        }
    }

    /**
     * @param resource $handle at the start of the file
     *
     * @return list<string>
     */
    private static function readHeader($handle, string $separator): array
    {
        $header = fgetcsv($handle, null, $separator, '"', '');
        if (!\is_array($header) || [null] === $header) {
            throw new CsvFileUnreadable(new Message('csv.no_header', 'The file has no header: its first line names the columns.'));
        }
        self::checkEncoding($header, 1);
        $header = array_map(static fn (?string $name): string => trim((string) $name), $header);
        $header[0] = self::withoutBom($header[0]);
        // The rows are keyed by header: two columns of one name would overwrite each other.
        foreach (array_diff_key($header, array_unique($header)) as $i => $name) {
            throw new CsvFileUnreadable(new Message('csv.header_twice', 'Column %position% repeats the header "%header%": give every column a name of its own, or remove it.', ['position' => $i + 1, 'header' => $name]));
        }

        return $header;
    }

    /**
     * @return resource
     */
    private static function open(string $path)
    {
        $handle = is_file($path) && is_readable($path) ? fopen($path, 'r') : false;
        if (false === $handle) {
            throw new CsvFileUnreadable(new Message('csv.unreadable', 'The file "%path%" cannot be read.', ['path' => $path]));
        }

        return $handle;
    }

    /**
     * @param array<int, string|null> $cells
     */
    private static function checkEncoding(array $cells, int $line): void
    {
        if (!mb_check_encoding(implode('', array_map(strval(...), $cells)), 'UTF-8')) {
            throw new CsvFileUnreadable(new Message('csv.not_utf8', 'Line %line% is not UTF-8: save the file as "CSV UTF-8".', ['line' => $line]));
        }
    }

    private static function withoutBom(string $text): string
    {
        return (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);
    }
}
