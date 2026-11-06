<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use Fedale\TemplateFormat\Document;
use Fedale\TemplateFormat\DocumentKind;
use Fedale\TemplateFormat\Package;

/**
 * A fingerprint of each record a seed wrote, by its key: what the record held then, in the
 * fields of the template. An upgrade compares it with the record now to tell whether the user
 * changed it: values written in a CSV file ("4") and stored ones (4, a date, an id) do not
 * compare, their fingerprints do.
 *
 * A field the user added to the object is left out: changing it changes nothing of the
 * template's.
 */
final class SeedFingerprints
{
    public function __construct(
        private readonly RecordFinderInterface $records,
    ) {
    }

    /**
     * @return array<string, array<string, string>> seed path => key => fingerprint
     */
    public function of(Package $package): array
    {
        $objects = [];
        foreach ($package->documentsOf(DocumentKind::Object) as $object) {
            $objects[$object->id] = $object;
        }
        $fingerprints = [];
        foreach ($package->documentsOf(DocumentKind::Seed) as $seed) {
            $object = $objects[\is_string($seed->spec['object'] ?? null) ? $seed->spec['object'] : ''] ?? null;
            if (null === $object) {
                continue;
            }
            $key = \is_string($seed->spec['key'] ?? null) ? $seed->spec['key'] : '';
            $fields = self::fieldNames($object);
            $fingerprints[$seed->path] = [];
            foreach ($this->current(self::string($object->spec['name'] ?? null), $key) as $value => $record) {
                $fingerprints[$seed->path][$value] = self::fingerprint($record, $fields);
            }
        }

        return $fingerprints;
    }

    /**
     * The live records of an object, by the value of their key.
     *
     * @return array<string, RecordOutput>
     */
    public function current(string $objectName, string $key): array
    {
        $records = [];
        foreach ($this->records->each($objectName) as $record) {
            $value = $record->values[$key] ?? null;
            if (\is_scalar($value) && '' !== trim((string) $value)) {
                $records[trim((string) $value)] = $record;
            }
        }

        return $records;
    }

    /**
     * @param list<string> $fields the fields compared, by name
     */
    public static function fingerprint(RecordOutput $record, array $fields): string
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = self::normalize($record->values[$field] ?? null);
        }
        ksort($values);

        return hash('sha256', json_encode($values, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string>
     */
    public static function fieldNames(Document $object): array
    {
        $names = [];
        foreach (\is_array($object->spec['fields'] ?? null) ? $object->spec['fields'] : [] as $field) {
            if (\is_array($field) && \is_string($field['name'] ?? null)) {
                $names[] = $field['name'];
            }
        }

        return $names;
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (\is_array($value)) {
            // The links of a many-to-many field, the files of a list: a set.
            $items = array_map(self::normalize(...), $value);
            sort($items);

            return $items;
        }

        return $value;
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \LogicException('An object has a name.');
    }
}
