<?php

declare(strict_types=1);

namespace App\Module\Package\Application\Upgrade;

use App\Shared\Message\Message;
use Fedale\PackageFormat\Document;
use Fedale\PackageFormat\DocumentKind;
use Fedale\PackageFormat\Package;
use Fedale\PackageFormat\SeedRecords;

/**
 * What an upgrade does to the records of the seeds, record by record (by key), from the
 * records installed, the new version's, and the records now:
 *
 * - a seed new in this version: its records are written, as at install;
 * - a `managed` seed: a record the new version added is created, one it changed is updated, one
 *   it removed is deleted; unless the user changed that record since (its fingerprint differs),
 *   or deleted it: a conflict;
 * - a `once` seed: its records belong to the user, nothing happens.
 *
 * @internal used by PackageUpgrader, before its transaction
 */
final class SeedPlanner
{
    public function __construct(
        private readonly SeedRecords $seedRecords,
        private readonly SeedFingerprints $fingerprints,
    ) {
    }

    /**
     * @param Package              $base     the version installed, its seeds' records inline
     * @param array<string, mixed> $snapshot the installation's snapshot, for the fingerprints
     * @param Package              $mine     what the instance holds now (names as they are)
     */
    public function plan(Package $base, array $snapshot, Package $theirs, Package $mine, Resolution $resolution): SeedChanges
    {
        $changes = new SeedChanges();
        $baseSeeds = self::byObject($base);
        $mineObjects = [];
        foreach ($mine->documentsOf(DocumentKind::Object) as $object) {
            $mineObjects[$object->id] = $object;
        }
        $theirObjects = [];
        foreach ($theirs->documentsOf(DocumentKind::Object) as $object) {
            $theirObjects[$object->id] = $object;
        }

        foreach ($theirs->documentsOf(DocumentKind::Seed) as $seed) {
            $objectId = self::string($seed->spec['object'] ?? null);
            $baseSeed = $baseSeeds[$objectId] ?? null;
            $managed = 'managed' === ($seed->spec['mode'] ?? 'once');
            if (null !== $baseSeed && !$managed) {
                continue;
            }
            $fields = self::fields($theirObjects[$objectId] ?? null);
            $key = self::string($seed->spec['key'] ?? null);
            $mineObject = $mineObjects[$objectId] ?? null;
            [$current, $mineFields] = null === $mineObject
                ? [[], []]
                : [$this->fingerprints->current(self::string($mineObject->spec['name'] ?? null), self::mineName($mineObject, $theirObjects[$objectId] ?? null, $key)), SeedFingerprints::fieldNames($mineObject)];

            $theirRows = $this->seedRecords->of($theirs, $seed);
            if (null === $baseSeed) {
                foreach ($theirRows as $i => $row) {
                    $value = self::key($row, $key);
                    $record = $current[$value] ?? null;
                    if (null === $record) {
                        $changes->write($seed, $i, null, self::create($value));
                    } else {
                        $this->conflict($changes, $resolution, $seed, $value, self::addedAndHere($value), fn () => $changes->write($seed, $i, $record->id, self::update($value)));
                    }
                }
                continue;
            }

            $baseRows = [];
            foreach ($this->seedRecords->of($base, $baseSeed) as $row) {
                $baseRows[self::key($row, $key)] = $row;
            }
            $printed = Snapshot::fingerprints($snapshot, $baseSeed->path);
            $changedHere = static fn (string $value): bool => isset($printed[$value], $current[$value]) && SeedFingerprints::fingerprint($current[$value], $mineFields) !== $printed[$value];
            foreach ($theirRows as $i => $row) {
                $value = self::key($row, $key);
                $record = $current[$value] ?? null;
                $old = $baseRows[$value] ?? null;
                unset($baseRows[$value]);
                if (null !== $old && self::normalized($old, $fields) === self::normalized($row, $fields)) {
                    continue;
                }
                $write = fn () => $changes->write($seed, $i, $record?->id, null === $record ? self::create($value) : self::update($value));
                match (true) {
                    null === $old && null === $record, null !== $old && null !== $record && !$changedHere($value) => $write(),
                    null === $old => $this->conflict($changes, $resolution, $seed, $value, self::addedAndHere($value), $write),
                    null === $record => $this->conflict($changes, $resolution, $seed, $value, new Message('package.upgrade.seed.changed_deleted_here', 'record %key%, changed in this version, was deleted here', ['key' => $value]), $write),
                    default => $this->conflict($changes, $resolution, $seed, $value, new Message('package.upgrade.seed.changed_changed_here', 'record %key%, changed in this version, was changed here too', ['key' => $value]), $write),
                };
            }
            // What is left of the version installed: the records this version removed.
            foreach (array_keys($baseRows) as $value) {
                $record = $current[(string) $value] ?? null;
                if (null === $record) {
                    continue;
                }
                $delete = fn () => $changes->delete($seed, $objectId, $record->id, new Message('package.upgrade.seed.delete', 'delete record %key%', ['key' => (string) $value]));
                $changedHere((string) $value)
                    ? $this->conflict($changes, $resolution, $seed, (string) $value, new Message('package.upgrade.seed.removed_changed_here', 'record %key%, removed in this version, was changed here', ['key' => (string) $value]), $delete)
                    : $delete();
            }
        }

        return $changes;
    }

    private function conflict(SeedChanges $changes, Resolution $resolution, Document $seed, string $key, Message $what, \Closure $overwrite): void
    {
        $changes->conflict($seed, $key, $what);
        if (Strategy::Overwrite === $resolution->for(\sprintf('record:%s:%s', $seed->path, $key))) {
            $overwrite();
        }
    }

    private static function create(string $key): Message
    {
        return new Message('package.upgrade.seed.create', 'create record %key%', ['key' => $key]);
    }

    private static function update(string $key): Message
    {
        return new Message('package.upgrade.seed.update', 'update record %key%', ['key' => $key]);
    }

    private static function addedAndHere(string $key): Message
    {
        return new Message('package.upgrade.seed.added_exists_here', 'record %key%, new in this version, already exists here', ['key' => $key]);
    }

    /**
     * A row as it compares: empty values left out, several values as a set, a boolean as a word.
     *
     * @param array<string, mixed>        $row
     * @param array<string, array<mixed>> $fields the object's fields, by name
     *
     * @return array<string, mixed>
     */
    private static function normalized(array $row, array $fields): array
    {
        $normalized = [];
        foreach ($row as $name => $value) {
            if (null === $value || '' === $value || [] === $value) {
                continue;
            }
            $field = $fields[$name] ?? [];
            $settings = \is_array($field['settings'] ?? null) ? $field['settings'] : [];
            if ('many_to_many' === ($field['type'] ?? null) || ('file' === ($field['type'] ?? null) && true === ($settings['multiple'] ?? false))) {
                $list = SeedRecords::list($value);
                sort($list);
                $normalized[$name] = $list;
                continue;
            }
            $normalized[$name] = match (true) {
                \is_bool($value) => $value ? 'true' : 'false',
                \is_scalar($value) => trim((string) $value),
                default => $value,
            };
        }
        ksort($normalized);

        return $normalized;
    }

    /**
     * @return array<string, Document> the seeds of a package, by the id of their object
     */
    private static function byObject(Package $package): array
    {
        $seeds = [];
        foreach ($package->documentsOf(DocumentKind::Seed) as $seed) {
            $seeds[self::string($seed->spec['object'] ?? null)] = $seed;
        }

        return $seeds;
    }

    /**
     * @return array<string, array<mixed>>
     */
    private static function fields(?Document $object): array
    {
        if (null === $object) {
            return [];
        }
        $fields = [];
        foreach (\is_array($object->spec['fields'] ?? null) ? $object->spec['fields'] : [] as $field) {
            if (\is_array($field) && \is_string($field['name'] ?? null)) {
                $fields[$field['name']] = $field;
            }
        }

        return $fields;
    }

    /**
     * The name the key field has here: the new version may rename it in this very upgrade.
     */
    private static function mineName(Document $mine, ?Document $theirs, string $key): string
    {
        $id = null;
        foreach (self::fields($theirs) as $name => $field) {
            if ($name === $key) {
                $id = $field['id'] ?? null;
            }
        }
        foreach (self::fields($mine) as $name => $field) {
            if (null !== $id && ($field['id'] ?? null) === $id) {
                return $name;
            }
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function key(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return \is_scalar($value) ? trim((string) $value) : '';
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \LogicException('A value the checker requires is missing.');
    }
}
