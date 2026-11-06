<?php

declare(strict_types=1);

namespace App\Module\Template\Application;

use App\Module\FieldType\Contract\Dto\FileUpload;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Template\Domain\Exception\InstallRefused;
use Fedale\TemplateFormat\Document;
use Fedale\TemplateFormat\DocumentKind;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\SeedRecords;

/**
 * Writes the records of a template's seeds, through the record pipeline: every record is
 * validated by the rules of its object, and its files are stored as uploads are.
 *
 * Records point at each other by key: a relation holds the key of a record of the target's
 * seed, a many-to-many field a list of keys. So a seed is written after the seeds it points at;
 * a reference to a record of the same seed not written yet (a category's parent below it) is
 * set once the whole seed is written.
 *
 * @internal used by TemplateInstaller, inside its transaction, on a checked package
 */
final class SeedWriter
{
    public function __construct(
        private readonly RecordManagerInterface $records,
        private readonly SeedRecords $seedRecords,
    ) {
    }

    /**
     * @param array<string, array<int, string|null>>|null $only the records written, by seed path and
     *                                                          index: the id of the record each updates,
     *                                                          null to create one; null to create every
     *                                                          record of every seed (an install)
     * @param array<string, array<string, string>>        $keys object id => key => record id, for the
     *                                                          records already there (an upgrade)
     *
     * @return int the records written
     */
    public function write(Package $package, PackageNames $names, ?array $only = null, array $keys = []): int
    {
        $fields = [];
        foreach ($package->documentsOf(DocumentKind::Object) as $object) {
            foreach (\is_array($object->spec['fields'] ?? null) ? $object->spec['fields'] : [] as $field) {
                if (\is_array($field) && \is_string($field['name'] ?? null)) {
                    $fields[$object->id][$field['name']] = $field;
                }
            }
        }

        $count = 0;
        foreach ($this->inOrder($package->documentsOf(DocumentKind::Seed), $fields) as $seed) {
            $object = self::string($seed->spec['object'] ?? null);
            $key = self::string($seed->spec['key'] ?? null);
            $deferred = [];
            foreach ($this->seedRecords->of($package, $seed) as $i => $record) {
                if (null !== $only && !\array_key_exists($i, $only[$seed->path] ?? [])) {
                    continue;
                }
                $existing = null === $only ? null : $only[$seed->path][$i];
                [$values, $later] = $this->values($seed, $i, $package, $object, $record, $fields[$object] ?? [], $keys, null !== $existing);
                $id = $this->save($seed, $i, $names->object($object), $existing, $values);
                $keys[$object][trim(self::string(self::scalar($record[$key] ?? null)))] = $id;
                if ([] !== $later) {
                    $deferred[] = [$i, $id, $later];
                }
                ++$count;
            }
            // The references within the seed, now that each of its records exists.
            foreach ($deferred as [$i, $id, $later]) {
                $values = [];
                foreach ($later as $name => [$type, $references]) {
                    $ids = array_map(static fn (string $reference): string => $keys[$object][$reference], $references);
                    $values[$name] = 'many_to_many' === $type ? $ids : ($ids[0] ?? null);
                }
                $this->save($seed, $i, $names->object($object), $id, $values);
            }
        }

        return $count;
    }

    /**
     * A record's values as the record pipeline takes them: keys turned into record ids, paths
     * into uploads; empty values left out, so that defaults apply. References to the record's
     * own seed are returned apart, to set once the seed is written.
     *
     * @param array<string, mixed>                 $record
     * @param array<string, array<mixed>>          $fields the object's fields, by name
     * @param array<string, array<string, string>> $keys   object id => key => record id
     * @param bool                                 $update the record exists: an empty value clears its field
     *
     * @return array{array<string, mixed>, array<string, array{string, list<string>}>}
     */
    private function values(Document $seed, int $i, Package $package, string $object, array $record, array $fields, array $keys, bool $update): array
    {
        $values = [];
        $later = [];
        foreach ($record as $name => $value) {
            if (null === $value || '' === $value) {
                if ($update) {
                    $values[$name] = null;
                }
                continue;
            }
            $field = $fields[$name] ?? [];
            $type = $field['type'] ?? null;
            $settings = \is_array($field['settings'] ?? null) ? $field['settings'] : [];
            if ('relation' === $type || 'many_to_many' === $type) {
                $target = self::string($settings['target'] ?? null);
                $references = 'many_to_many' === $type ? SeedRecords::list($value) : [trim(self::string(self::scalar($value)))];
                if ($target === $object) {
                    $later[$name] = [$type, $references];
                    continue;
                }
                $ids = [];
                foreach ($references as $reference) {
                    // At install the checker has made sure of it; at an upgrade, the user may have
                    // deleted the record since.
                    $ids[] = $keys[$target][$reference] ?? throw InstallRefused::at($seed->path, self::at($seed, $i).'.'.$name, \sprintf('No record of "%s" has the key "%s".', $target, $reference));
                }
                $values[$name] = 'many_to_many' === $type ? $ids : $ids[0];
                continue;
            }
            if ('file' === $type) {
                $uploads = array_map(fn (string $path): FileUpload => new FileUpload($package->file($path) ?? throw new \LogicException(\sprintf('No file "%s" in the package.', $path)), basename($path)), SeedRecords::list($value));
                $values[$name] = true === ($settings['multiple'] ?? false) ? $uploads : $uploads[0];
                continue;
            }
            $values[$name] = $value;
        }

        return [$values, $later];
    }

    /**
     * @param array<Document>                            $seeds
     * @param array<string, array<string, array<mixed>>> $fields object id => field name => field
     *
     * @return list<Document> each after the seeds its records point at
     */
    private function inOrder(array $seeds, array $fields): array
    {
        $pending = [];
        foreach ($seeds as $seed) {
            $object = self::string($seed->spec['object'] ?? null);
            $targets = [];
            foreach ($fields[$object] ?? [] as $field) {
                $target = \is_array($field['settings'] ?? null) ? ($field['settings']['target'] ?? null) : null;
                if (\in_array($field['type'] ?? null, ['relation', 'many_to_many'], true) && \is_string($target) && $target !== $object) {
                    $targets[] = $target;
                }
            }
            $pending[$object] = [$seed, $targets];
        }

        $ordered = [];
        while ([] !== $pending) {
            // A target without a seed holds no record a seed could point at: no need to wait for it.
            $ready = array_filter($pending, static fn (array $entry): bool => [] === array_intersect($entry[1], array_keys($pending)));
            if ([] === $ready) {
                [$seed] = reset($pending);
                throw InstallRefused::at($seed->path, 'spec.object', 'Its records and those of another seed point at each other: one of the two references must be left empty.');
            }
            foreach ($ready as $object => [$seed]) {
                $ordered[] = $seed;
                unset($pending[$object]);
            }
        }

        return $ordered;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function save(Document $seed, int $i, string $objectName, ?string $id, array $values): string
    {
        try {
            return null === $id
                ? $this->records->create(new RecordInput($objectName, $values))->id
                : $this->records->update($id, new RecordInput($objectName, $values))->id;
        } catch (RecordValidationFailed $e) {
            throw InstallRefused::at($seed->path, self::at($seed, $i), implode(' ', array_map(static fn (string $field, array $messages): string => \sprintf('%s: %s', '' === $field ? 'record' : $field, implode(' ', $messages)), array_keys($e->errors), $e->errors)));
        }
    }

    /**
     * Where a record is: "spec.records.0" inline, "seeds/products.csv:2" in a CSV file.
     */
    private static function at(Document $seed, int $i): string
    {
        return \is_string($seed->spec['source'] ?? null) ? \sprintf('%s:%d', $seed->spec['source'], $i + 2) : \sprintf('spec.records.%d', $i);
    }

    private static function scalar(mixed $value): ?string
    {
        return \is_scalar($value) ? (string) $value : null;
    }

    private static function string(mixed $value): string
    {
        // The checker has validated the package: a miss is a bug.
        return \is_string($value) ? $value : throw new \LogicException('A value the checker requires is missing.');
    }
}
