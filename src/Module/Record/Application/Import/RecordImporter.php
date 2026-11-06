<?php

declare(strict_types=1);

namespace App\Module\Record\Application\Import;

use App\Module\FieldType\Contract\Dto\FileUpload;
use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Record\Domain\Exception\ImportRefused;
use App\Module\Record\Domain\Repository\RecordStore;
use App\Shared\Csv\CsvFile;
use App\Shared\Transaction\TransactionRunner;

/**
 * Imports the rows of a CSV file into an object, all or nothing: every row goes through the
 * record pipeline (the object's rules, files stored as uploads), in one transaction; any row
 * refused, and nothing is written. Every refused row is reported, with its line.
 *
 * - A key (a unique field) makes it an upsert: a row whose key a record holds updates it.
 * - A relation or many-to-many cell holds record ids, or, matched by a unique field of the
 *   target, values of that field. Several ids or values are separated by "|".
 * - A file cell holds a path relative to the CSV file's directory, inside it.
 */
final class RecordImporter
{
    private const string LIST_SEPARATOR = '|';

    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordManagerInterface $records,
        private readonly RecordStore $store,
        private readonly TransactionRunner $transactions,
    ) {
    }

    /**
     * @param array<string, string> $matches relation field => the unique field of its target its cells hold
     *
     * @throws ImportRefused     nothing was written
     * @throws MetadataException the object does not exist
     */
    public function import(string $objectName, string $file, ?string $key = null, array $matches = [], bool $dryRun = false): ImportReport
    {
        $object = $this->metadata->getObject($objectName);
        try {
            $rows = CsvFile::read($file);
        } catch (\UnexpectedValueException $e) {
            throw new ImportRefused([$e->getMessage()]);
        }
        $header = array_map(strval(...), array_keys($rows[0]['values'] ?? []));
        $keyField = null === $key ? null : $this->uniqueField($object, $key, '--key');
        $reasons = [...$this->headerProblems($object, $header), ...$this->duplicateKeys($rows, $keyField)];
        $targets = [];
        foreach ($matches as $fieldName => $targetFieldName) {
            $field = $object->fields[$fieldName] ?? null;
            $target = $field?->target();
            if (null === $field || null === $target) {
                $reasons[] = \sprintf('--match: "%s" is no relation or many-to-many field of "%s".', $fieldName, $object->name);
                continue;
            }
            $targets[$fieldName] = [$this->metadata->getObject($target), $this->uniqueField($this->metadata->getObject($target), $targetFieldName, '--match')];
        }
        if ([] !== $reasons) {
            throw new ImportRefused($reasons);
        }

        $existing = null === $keyField ? [] : $this->store->idsByValues($object, $keyField, array_values(array_filter(array_map(
            static fn (array $row): ?string => null === $row['values'][$keyField->name] ? null : trim($row['values'][$keyField->name]),
            $rows,
        ), is_string(...))));
        $references = $this->references($object, $rows, $targets);

        try {
            return $this->transactions->run(function () use ($object, $rows, $keyField, $existing, $references, $file, $dryRun): ImportReport {
                $created = 0;
                $updated = 0;
                $reasons = [];
                foreach ($rows as ['line' => $line, 'values' => $values]) {
                    $id = null === $keyField ? null : ($existing[trim((string) $values[$keyField->name])] ?? null);
                    [$input, $problems] = $this->input($object, $values, $references, \dirname($file), null === $id);
                    if ([] !== $problems) {
                        $reasons = [...$reasons, ...array_map(static fn (string $problem): string => \sprintf('Line %d: %s', $line, $problem), $problems)];
                        continue;
                    }
                    try {
                        if (null === $id) {
                            $this->records->create($input);
                            ++$created;
                        } else {
                            $this->records->update($id, $input);
                            ++$updated;
                        }
                    } catch (RecordValidationFailed $e) {
                        foreach ($e->errors as $field => $messages) {
                            $reasons[] = \sprintf('Line %d: %s: %s', $line, '' === $field ? 'record' : $field, implode(' ', $messages));
                        }
                    }
                }
                $report = new ImportReport($created, $updated, $dryRun);
                if ([] !== $reasons || $dryRun) {
                    throw new ImportRolledBack($report, $reasons);
                }

                return $report;
            });
        } catch (ImportRolledBack $rolledBack) {
            if ([] !== $rolledBack->reasons) {
                throw new ImportRefused($rolledBack->reasons);
            }

            return $rolledBack->report;
        }
    }

    /**
     * A row's values as the record pipeline takes them, with the problems found on the way.
     *
     * @param array<string, string|null>                $values
     * @param array<string, array<string, string|null>> $references field => cell value => record id (null: no record)
     * @param bool                                      $isNew      the row creates a record (no key, or a key no record holds)
     *
     * @return array{RecordInput, list<string>}
     */
    private function input(ObjectDefinitionDto $object, array $values, array $references, string $directory, bool $isNew): array
    {
        $input = [];
        $problems = [];
        foreach ($values as $name => $value) {
            $field = $object->fields[$name];
            if (null === $value) {
                // An empty cell: a new record takes the field's default, an existing one is cleared.
                if (!$isNew) {
                    $input[$name] = null;
                }
                continue;
            }
            if (isset($references[$name])) {
                $ids = [];
                foreach ($this->list($field, $value) as $item) {
                    $id = $references[$name][$item] ?? null;
                    if (null === $id) {
                        $problems[] = \sprintf('%s: no record matches "%s".', $name, $item);
                    } else {
                        $ids[] = $id;
                    }
                }
                $input[$name] = null !== $field->manyToMany() ? $ids : ($ids[0] ?? null);
                continue;
            }
            if ('file' === $field->type) {
                $uploads = [];
                foreach ($this->list($field, $value) as $path) {
                    $real = self::inside($directory, $path);
                    if (null === $real) {
                        $problems[] = \sprintf('%s: no file "%s" next to the CSV file.', $name, $path);
                    } else {
                        $uploads[] = new FileUpload($real, basename($path));
                    }
                }
                $input[$name] = true === ($field->settings['multiple'] ?? false) ? $uploads : ($uploads[0] ?? null);
                continue;
            }
            $input[$name] = null !== $field->manyToMany() ? $this->list($field, $value) : $value;
        }

        return [new RecordInput($object->name, $input), $problems];
    }

    /**
     * The record ids of the cells of the matched relation fields, looked up at once per field.
     *
     * @param list<array{line: int, values: array<string, string|null>}>    $rows
     * @param array<string, array{ObjectDefinitionDto, FieldDefinitionDto}> $targets
     *
     * @return array<string, array<string, string|null>>
     */
    private function references(ObjectDefinitionDto $object, array $rows, array $targets): array
    {
        $references = [];
        foreach ($targets as $name => [$target, $targetField]) {
            $values = [];
            foreach ($rows as $row) {
                $values = [...$values, ...$this->list($object->fields[$name], $row['values'][$name] ?? null)];
            }
            $references[$name] = $this->store->idsByValues($target, $targetField, $values);
        }
        // The other relation fields hold ids: the record pipeline checks they exist.
        foreach ($object->fields as $name => $field) {
            if (null !== $field->target() && !isset($targets[$name])) {
                foreach ($rows as $row) {
                    foreach ($this->list($field, $row['values'][$name] ?? null) as $id) {
                        $references[$name][$id] = $id;
                    }
                }
            }
        }

        return $references;
    }

    /**
     * @param list<string> $header
     *
     * @return list<string>
     */
    private function headerProblems(ObjectDefinitionDto $object, array $header): array
    {
        $problems = [];
        foreach ($header as $name) {
            if (!isset($object->fields[$name])) {
                $problems[] = \sprintf('Column "%s": "%s" has no such field.', $name, $object->name);
            }
        }

        return $problems;
    }

    /**
     * @param list<array{line: int, values: array<string, string|null>}> $rows
     *
     * @return list<string>
     */
    private function duplicateKeys(array $rows, ?FieldDefinitionDto $key): array
    {
        if (null === $key) {
            return [];
        }
        $problems = [];
        $seen = [];
        foreach ($rows as ['line' => $line, 'values' => $values]) {
            $value = trim((string) ($values[$key->name] ?? ''));
            if ('' === $value) {
                $problems[] = \sprintf('Line %d: %s: the key is missing.', $line, $key->name);
            } elseif (isset($seen[$value])) {
                $problems[] = \sprintf('Line %d: %s: line %d has the same key.', $line, $key->name, $seen[$value]);
            }
            $seen[$value] ??= $line;
        }

        return $problems;
    }

    private function uniqueField(ObjectDefinitionDto $object, string $name, string $option): FieldDefinitionDto
    {
        $field = $object->fields[$name] ?? null;
        if (null === $field || !$field->isUnique) {
            throw new ImportRefused([\sprintf('%s: "%s" is no unique field of "%s".', $option, $name, $object->name)]);
        }

        return $field;
    }

    /**
     * The values of a cell: several for a many-to-many field or a list of files, one otherwise.
     *
     * @return list<string>
     */
    private function list(FieldDefinitionDto $field, ?string $value): array
    {
        if (null === $value) {
            return [];
        }
        $several = null !== $field->manyToMany() || ('file' === $field->type && true === ($field->settings['multiple'] ?? false));
        $items = $several ? explode(self::LIST_SEPARATOR, $value) : [$value];

        return array_values(array_filter(array_map(trim(...), $items), static fn (string $item): bool => '' !== $item));
    }

    /**
     * A path inside the directory, never out of it: no "..", no absolute path, no symbolic link.
     */
    private static function inside(string $directory, string $path): ?string
    {
        $root = realpath($directory);
        $real = realpath($directory.'/'.$path);
        if (false === $root || false === $real || str_starts_with($path, '/') || !str_starts_with($real, $root.'/')
            || is_link($directory.'/'.$path) || !is_file($real)) {
            return null;
        }

        return $real;
    }
}
