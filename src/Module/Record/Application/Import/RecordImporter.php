<?php

declare(strict_types=1);

namespace App\Module\Record\Application\Import;

use App\Module\FieldType\Contract\Dto\FileUpload;
use App\Module\FieldType\Contract\IsoCodesInterface;
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
use App\Shared\Csv\CsvFileUnreadable;
use App\Shared\Message\Message;
use App\Shared\Message\MessageList;
use App\Shared\Transaction\TransactionRunner;

/**
 * Imports the rows of a CSV file into an object, all or nothing: every row goes through the
 * record pipeline (the object's rules, files stored as uploads), in one transaction; any row
 * refused, and nothing is written. Every refused row is reported, with its line.
 *
 * - A key (a unique field) makes it an upsert: a row whose key a record holds updates it.
 * - A relation or many-to-many cell holds record ids, or, matched by a unique field of the
 *   target, values of that field. Several ids or values are separated by "|".
 * - A file cell holds a path relative to a directory (the CSV file's), inside it. Rows read
 *   elsewhere (the web wizard) come without one: a file column is refused.
 */
final class RecordImporter
{
    private const string LIST_SEPARATOR = '|';

    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordManagerInterface $records,
        private readonly RecordStore $store,
        private readonly TransactionRunner $transactions,
        private readonly IsoCodesInterface $isoCodes,
    ) {
    }

    /**
     * Imports a CSV file, its header naming the fields; its file cells name files next to it.
     *
     * @param array<string, string> $matches relation field => the unique field of its target its cells hold
     *
     * @throws ImportRefused     nothing was written
     * @throws MetadataException the object does not exist
     */
    public function import(string $objectName, string $file, ?string $key = null, array $matches = [], bool $dryRun = false): ImportReport
    {
        $this->metadata->getObject($objectName);
        try {
            $rows = CsvFile::read($file);
        } catch (CsvFileUnreadable $e) {
            throw new ImportRefused([$e->text]);
        }

        return $this->importRows($objectName, $rows, $key, $matches, $dryRun, \dirname($file));
    }

    /**
     * Imports rows already read, their values named by field, in one transaction.
     *
     * @param list<array{line: int, values: array<string, string|null>}> $rows           each with its line in the file, for the reasons
     * @param array<string, string>                                      $matches        relation field => the unique field of its target its cells hold
     * @param string|null                                                $filesDirectory where the paths of file cells lead; null: no file column
     *
     * @throws ImportRefused     nothing was written
     * @throws MetadataException the object does not exist
     */
    public function importRows(string $objectName, array $rows, ?string $key = null, array $matches = [], bool $dryRun = false, ?string $filesDirectory = null): ImportReport
    {
        $plan = $this->prepare($objectName, array_map(strval(...), array_keys($rows[0]['values'] ?? [])), $key, $matches, $filesDirectory);
        $reasons = [...$plan->problems, ...$this->keyProblems($plan, $rows)];
        if ([] !== $reasons) {
            throw new ImportRefused($reasons);
        }

        $batch = $this->importBatch($plan, $rows, $dryRun);
        if ([] !== $batch->reasons) {
            throw new ImportRefused($batch->reasons);
        }

        return new ImportReport($batch->created, $batch->updated, $dryRun);
    }

    /**
     * What an import of these columns needs, checked once: the fields of the header, the key, the
     * matches. Its problems are the header's and the matches'; a key or a match naming no unique
     * field is refused at once.
     *
     * @param list<string>          $header         the fields the columns fill
     * @param array<string, string> $matches        relation field => the unique field of its target its cells hold
     * @param string|null           $filesDirectory where the paths of file cells lead; null: no file column
     *
     * @throws ImportRefused     the key or a match names no unique field
     * @throws MetadataException the object does not exist
     */
    public function prepare(string $objectName, array $header, ?string $key = null, array $matches = [], ?string $filesDirectory = null): ImportPlan
    {
        $object = $this->metadata->getObject($objectName);
        $keyField = null === $key ? null : $this->uniqueField($object, $key, '--key');
        $problems = $this->headerProblems($object, $header, null !== $filesDirectory);
        $targets = [];
        foreach ($matches as $fieldName => $targetFieldName) {
            $field = $object->fields[$fieldName] ?? null;
            $target = $field?->target();
            if (null === $field || null === $target) {
                $problems[] = new Message('record.import.match_not_relation', '--match: "%field%" is no relation or many-to-many field of "%object%".', ['field' => $fieldName, 'object' => $object->name]);
                continue;
            }
            $targets[$fieldName] = [$this->metadata->getObject($target), $this->uniqueField($this->metadata->getObject($target), $targetFieldName, '--match')];
        }

        return new ImportPlan($object, $keyField, $targets, $filesDirectory, $problems);
    }

    /**
     * The missing and repeated keys of all the rows: a pass of its own, before any batch, which
     * keeps only the keys in memory.
     *
     * @param iterable<array{line: int, values: array<string, string|null>}> $rows
     *
     * @return list<Message>
     */
    public function keyProblems(ImportPlan $plan, iterable $rows): array
    {
        if (null === $plan->keyField) {
            return [];
        }
        $key = $plan->keyField->name;
        $problems = [];
        $seen = [];
        foreach ($rows as ['line' => $line, 'values' => $values]) {
            $value = trim((string) ($values[$key] ?? ''));
            if ('' === $value) {
                $problems[] = new Message('record.import.key_missing', 'Line %line%: %field%: the key is missing.', ['line' => $line, 'field' => $key]);
            } elseif (isset($seen[$value])) {
                $problems[] = new Message('record.import.key_twice', 'Line %line%: %field%: line %first% has the same key.', ['line' => $line, 'field' => $key, 'first' => $seen[$value]]);
            }
            $seen[$value] ??= $line;
        }

        return $problems;
    }

    /**
     * Writes rows in one transaction, rolled back on a dry run or when a row is refused (its
     * reasons in the result, nothing written). $beforeCommit runs inside the transaction, only
     * when it commits: what it writes is kept with the rows, or not at all.
     *
     * @param list<array{line: int, values: array<string, string|null>}> $rows
     * @param (callable(ImportBatch): void)|null                         $beforeCommit
     */
    public function importBatch(ImportPlan $plan, array $rows, bool $dryRun = false, ?callable $beforeCommit = null): ImportBatch
    {
        $object = $plan->object;
        $keyField = $plan->keyField;
        $existing = null === $keyField ? [] : $this->store->idsByValues($object, $keyField, array_values(array_filter(array_map(
            static fn (array $row): ?string => null === $row['values'][$keyField->name] ? null : trim($row['values'][$keyField->name]),
            $rows,
        ), is_string(...))));
        $references = $this->references($object, $rows, $plan->targets);
        $filesDirectory = $plan->filesDirectory;

        try {
            return $this->transactions->run(function () use ($object, $rows, $keyField, $existing, $references, $filesDirectory, $dryRun, $beforeCommit): ImportBatch {
                $created = 0;
                $updated = 0;
                $reasons = [];
                foreach ($rows as ['line' => $line, 'values' => $values]) {
                    $id = null === $keyField ? null : ($existing[trim((string) $values[$keyField->name])] ?? null);
                    [$input, $problems] = $this->input($object, $values, $references, (string) $filesDirectory, null === $id);
                    if ([] !== $problems) {
                        $reasons = [...$reasons, ...array_map(static fn (Message $problem): Message => self::atLine($line, $problem), $problems)];
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
                            $reason = new MessageList($messages, ' ');
                            $reasons[] = self::atLine($line, RecordValidationFailed::GLOBAL_ERRORS === $field ? $reason : self::inField($field, $reason));
                        }
                    }
                }
                $batch = new ImportBatch($created, $updated, $reasons);
                if ([] !== $reasons || $dryRun) {
                    throw new ImportRolledBack($batch);
                }
                if (null !== $beforeCommit) {
                    $beforeCommit($batch);
                }

                return $batch;
            });
        } catch (ImportRolledBack $rolledBack) {
            return $rolledBack->batch;
        }
    }

    /**
     * A row's values as the record pipeline takes them, with the problems found on the way.
     *
     * @param array<string, string|null>                $values
     * @param array<string, array<string, string|null>> $references field => cell value => record id (null: no record)
     * @param bool                                      $isNew      the row creates a record (no key, or a key no record holds)
     *
     * @return array{RecordInput, list<Message>}
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
                        $problems[] = self::inField($name, new Message('record.import.no_match', 'No record matches "%value%".', ['value' => $item]));
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
                        $problems[] = self::inField($name, new Message('record.import.no_file', 'No file "%path%" next to the CSV file.', ['path' => $path]));
                    } else {
                        $uploads[] = new FileUpload($real, basename($path));
                    }
                }
                $input[$name] = true === ($field->settings['multiple'] ?? false) ? $uploads : ($uploads[0] ?? null);
                continue;
            }
            $input[$name] = match (true) {
                null !== $field->manyToMany(), 'tags' === $field->type => $this->list($field, $value),
                // A country, a language, a currency: its code, or its name as a person writes it.
                default => $this->isoCodes->code($field->type, $value) ?? $value,
            };
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
     * @param bool         $files  the file cells may name files (the rows come with a directory)
     *
     * @return list<Message>
     */
    private function headerProblems(ObjectDefinitionDto $object, array $header, bool $files): array
    {
        $problems = [];
        foreach ($header as $name) {
            if (!isset($object->fields[$name])) {
                $problems[] = new Message('record.import.no_such_field', 'Column "%column%": "%object%" has no such field.', ['column' => $name, 'object' => $object->name]);
            } elseif (!$files && 'file' === $object->fields[$name]->type) {
                $problems[] = new Message('record.import.file_column', 'Column "%column%": a file field is imported from the console only (record:import), with its files next to the CSV file.', ['column' => $name]);
            }
        }

        return $problems;
    }

    private function uniqueField(ObjectDefinitionDto $object, string $name, string $option): FieldDefinitionDto
    {
        $field = $object->fields[$name] ?? null;
        if (null === $field || !$field->isUnique) {
            throw new ImportRefused([new Message('record.import.not_unique', '%option%: "%field%" is no unique field of "%object%".', ['option' => $option, 'field' => $name, 'object' => $object->name])]);
        }

        return $field;
    }

    /**
     * A reason of a row: the line of the file first.
     */
    private static function atLine(int $line, Message|MessageList $reason): Message
    {
        return new Message('record.import.line', 'Line %line%: %reason%', ['line' => $line, 'reason' => $reason]);
    }

    /**
     * A reason of a cell: the field it fills first.
     */
    private static function inField(string $field, Message|MessageList $reason): Message
    {
        return new Message('record.import.field', '%field%: %reason%', ['field' => $field, 'reason' => $reason]);
    }

    /**
     * The values of a cell: several for a many-to-many field, a list of files or the labels of
     * a tags field, one otherwise.
     *
     * @return list<string>
     */
    private function list(FieldDefinitionDto $field, ?string $value): array
    {
        if (null === $value) {
            return [];
        }
        $several = null !== $field->manyToMany() || 'tags' === $field->type || ('file' === $field->type && true === ($field->settings['multiple'] ?? false));
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
