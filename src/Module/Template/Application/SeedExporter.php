<?php

declare(strict_types=1);

namespace App\Module\Template\Application;

use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\File\Contract\FileStorageInterface;
use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Template\Domain\Exception\InvalidExport;
use App\TemplateFormat\Document;
use App\TemplateFormat\DocumentKind;
use App\TemplateFormat\PackageFile;
use App\TemplateFormat\SeedRecords;
use App\TemplateFormat\StringFile;

/**
 * Writes the records of exported objects as seeds: one CSV file per object, its files copied
 * into the template. Records point at each other by key, as an install reads them back: a
 * relation is written as the key of its record, a many-to-many field as keys separated by "|".
 *
 * @internal used by TemplateExporter
 */
final class SeedExporter
{
    public function __construct(
        private readonly RecordFinderInterface $records,
        private readonly FieldTypeRegistryInterface $fieldTypes,
        private readonly FileStorageInterface $files,
    ) {
    }

    /**
     * @param array<string, ObjectDefinitionDto> $objects the exported objects, by name
     * @param array<string, string|null>         $seeds   object name => its key field, null for the first unique one
     *
     * @return array{list<Document>, array<string, PackageFile>}
     *
     * @throws InvalidExport
     */
    public function export(string $prefix, array $objects, array $seeds, ItemReferences $refs): array
    {
        $keys = [];
        $records = [];
        foreach ($seeds as $name => $key) {
            $object = $objects[$name] ?? throw InvalidExport::seedOfNoObject($name);
            $key = $this->keyField($object, $key);
            $records[$name] = [];
            foreach ($this->records->each($name) as $record) {
                $value = self::text($record->values[$key->name] ?? null, $key);
                if (null === $value || '' === trim($value)) {
                    throw InvalidExport::recordWithoutKey($name, $key->name, $record->id);
                }
                $keys[$name][$record->id] = $value;
                $records[$name][] = $record;
            }
            $seeds[$name] = $key->name;
        }

        $documents = [];
        $files = [];
        foreach ($seeds as $name => $key) {
            $object = $objects[$name];
            $fields = $this->exportedFields($object);
            $rows = [];
            foreach ($records[$name] as $record) {
                $row = [];
                foreach ($fields as $field) {
                    $row[$field->name] = $this->cell($object, $field, $record, $keys, $files);
                }
                $rows[] = $row;
            }
            $source = \sprintf('seeds/%s.csv', $name);
            $files[$source] = new StringFile(SeedRecords::toCsv(array_keys($fields), $rows));
            $documents[] = new Document(DocumentKind::Seed, \sprintf('%s.seed.%s', $prefix, $name), [
                'object' => $refs->object($name),
                'key' => $key,
                'source' => $source,
            ], \sprintf('seeds/%s.yaml', $name));
        }

        return [$documents, $files];
    }

    /**
     * The key named, or else the first unique field: it must be unique, and readable.
     */
    private function keyField(ObjectDefinitionDto $object, ?string $name): FieldDefinitionDto
    {
        if (null !== $name) {
            $field = $object->fields[$name] ?? throw InvalidExport::noKey($object->name, \sprintf('it has no field "%s"', $name));
            if (!$field->isUnique || $this->fieldTypes->get($field->type)->isWriteOnly()) {
                throw InvalidExport::noKey($object->name, \sprintf('"%s" is not a unique field', $name));
            }

            return $field;
        }
        foreach ($this->exportedFields($object) as $field) {
            if ($field->isUnique) {
                return $field;
            }
        }

        throw InvalidExport::noKey($object->name, 'it has no unique field');
    }

    /**
     * The fields a seed writes, in their order: every one but the write-only ones (a password's
     * hash is never read back).
     *
     * @return array<string, FieldDefinitionDto>
     */
    private function exportedFields(ObjectDefinitionDto $object): array
    {
        $fields = array_filter($object->fields, fn (FieldDefinitionDto $field): bool => !$this->fieldTypes->get($field->type)->isWriteOnly());
        uasort($fields, static fn (FieldDefinitionDto $a, FieldDefinitionDto $b): int => $a->position <=> $b->position);

        return $fields;
    }

    /**
     * @param array<string, array<string, string>> $keys  object name => record id => key
     * @param array<string, PackageFile>           $files the files of the template, filled here
     */
    private function cell(ObjectDefinitionDto $object, FieldDefinitionDto $field, RecordOutput $record, array $keys, array &$files): ?string
    {
        $value = $record->values[$field->name] ?? null;
        if (null === $value || [] === $value) {
            return null;
        }
        $target = $field->target();
        if (null !== $target) {
            if (!isset($keys[$target])) {
                throw InvalidExport::seedOutsideTheExport($object->name, $field->name, $target);
            }
            $references = [];
            foreach (\is_array($value) ? $value : [$value] as $id) {
                $reference = \is_string($id) ? ($keys[$target][$id] ?? null) : null;
                // A link to a record in the bin: the record is not exported, nor is the link.
                if (null === $reference) {
                    continue;
                }
                if (null !== $field->manyToMany() && str_contains($reference, SeedRecords::LIST_SEPARATOR)) {
                    throw InvalidExport::keyWithSeparator($target, $reference);
                }
                $references[] = $reference;
            }

            return [] === $references ? null : implode(SeedRecords::LIST_SEPARATOR, $references);
        }
        if ('file' === $field->type) {
            $paths = [];
            foreach (\is_array($value) ? $value : [$value] as $id) {
                $stored = \is_string($id) ? $this->files->find($id) : null;
                if (null === $stored) {
                    continue;
                }
                // One directory per file: two files of one name do not clash, and the name stays.
                $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $stored->originalName), '._') ?: 'file';
                $path = \sprintf('files/%s/%s/%s', $object->name, $stored->id, $name);
                $files[$path] = new StoredPackageFile($this->files, $stored->id);
                $paths[] = $path;
            }

            return [] === $paths ? null : implode(SeedRecords::LIST_SEPARATOR, $paths);
        }

        return self::text($value, $field);
    }

    /**
     * A plain value as a CSV cell, written so that an install reads it back the same.
     */
    private static function text(mixed $value, FieldDefinitionDto $field): ?string
    {
        return match (true) {
            null === $value => null,
            \is_bool($value) => $value ? 'true' : 'false',
            $value instanceof \DateTimeInterface => $value->format(true === ($field->settings['includeTime'] ?? false) ? 'Y-m-d H:i:s' : 'Y-m-d'),
            \is_scalar($value) => (string) $value,
            default => throw new \LogicException(\sprintf('Field "%s" holds a value a seed cannot write.', $field->name)),
        };
    }
}
