<?php

declare(strict_types=1);

namespace App\Module\File\Infrastructure\Dbal;

use App\Module\File\Application\Dto\CollectedFile;
use App\Module\File\Application\Dto\GarbageReport;
use App\Module\File\Application\FileGarbageCollectorInterface;
use App\Module\File\Infrastructure\StorageKeys;
use App\Shared\Database\TenantScope;
use App\Shared\Tenant\CurrentTenant;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * The references are read from the catalog, not from the metadata: every foreign key to
 * core.file is one (the file columns and the link tables of the file lists, archived or not,
 * since archiving keeps them). The File module needs no other module, and a table added later
 * with such a key counts with no change here.
 */
final class DbalFileGarbageCollector implements FileGarbageCollectorInterface
{
    // Bounds the parameters of one statement.
    private const int CHUNK = 1000;

    public function __construct(
        private readonly Connection $connection,
        #[Target('record_files.storage')]
        private readonly FilesystemOperator $storage,
        private readonly CurrentTenant $tenant,
    ) {
    }

    public function collect(\DateTimeImmutable $storedBefore, bool $dryRun = false): GarbageReport
    {
        $files = $this->unreferencedFiles($storedBefore, $dryRun);
        // On a dry run the rows are still there: their contents count as freed unless another
        // row uses them.
        $ignoredIds = $dryRun ? array_map(static fn (CollectedFile $file): string => $file->id, $files) : [];

        [$contents, $thumbnails] = $this->storedContents();
        $freedContents = array_diff_key($contents, $this->heldKeys(array_keys($contents), $ignoredIds));
        $freedThumbnails = array_diff_key($thumbnails, $this->heldHashes(array_keys($thumbnails), $ignoredIds));

        $contentCount = 0;
        $contentBytes = 0;
        foreach ($freedContents as $key => $size) {
            if ($dryRun || $this->deleteContent($key)) {
                ++$contentCount;
                $contentBytes += $size;
            }
        }
        $thumbnailCount = 0;
        foreach ($freedThumbnails as $keys) {
            foreach ($keys as $key) {
                // A thumbnail is made again on demand: no lock, a late one goes at the next run.
                if (!$dryRun) {
                    $this->storage->delete($key);
                }
                ++$thumbnailCount;
            }
        }

        return new GarbageReport($files, $contentCount, $contentBytes, $thumbnailCount);
    }

    /**
     * The current tenant's rows referenced by nothing, deleted unless on a dry run. The DELETE repeats the
     * condition, so a row referenced since it was read stays (and the RESTRICT foreign keys
     * would refuse it anyway).
     *
     * @return list<CollectedFile> oldest first
     */
    private function unreferencedFiles(\DateTimeImmutable $storedBefore, bool $dryRun): array
    {
        $unreferenced = $this->unreferencedCondition();
        /** @var list<array{id: string, original_name: string, size: int|string, created_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT f.id, f.original_name, f.size, f.created_at FROM core.file f WHERE f.tenant_id = %s AND f.created_at < :before AND %s ORDER BY f.created_at, f.id', TenantScope::CURRENT, $unreferenced),
            ['before' => $storedBefore],
            ['before' => Types::DATETIME_IMMUTABLE],
        );
        if (!$dryRun && [] !== $rows) {
            $deleted = [];
            foreach (array_chunk(array_column($rows, 'id'), self::CHUNK) as $ids) {
                $deleted = [...$deleted, ...$this->connection->fetchFirstColumn(
                    \sprintf('DELETE FROM core.file f WHERE f.tenant_id = %s AND f.id IN (:ids) AND %s RETURNING f.id', TenantScope::CURRENT, $unreferenced),
                    ['ids' => $ids],
                    ['ids' => ArrayParameterType::STRING],
                )];
            }
            $rows = array_filter($rows, static fn (array $row): bool => \in_array($row['id'], $deleted, true));
        }

        $files = [];
        foreach ($rows as $row) {
            $files[] = new CollectedFile($row['id'], $row['original_name'], (int) $row['size'], new \DateTimeImmutable($row['created_at']));
        }

        return $files;
    }

    /**
     * "Nothing points at f.id": one NOT EXISTS per foreign key to core.file, each served by
     * the index on its column (the Schema module indexes every one).
     */
    private function unreferencedCondition(): string
    {
        /** @var list<array{schema: string, table: string, column: string}> $references */
        $references = $this->connection->fetchAllAssociative(
            "SELECT n.nspname AS schema, c.relname AS table, a.attname AS column
               FROM pg_constraint k
               JOIN pg_class c ON c.oid = k.conrelid
               JOIN pg_namespace n ON n.oid = c.relnamespace
               JOIN pg_attribute a ON a.attrelid = k.conrelid AND a.attnum = k.conkey[1]
              WHERE k.contype = 'f' AND k.confrelid = 'core.file'::regclass AND cardinality(k.conkey) = 1
              ORDER BY 1, 2, 3",
        );
        $conditions = [];
        foreach ($references as $reference) {
            $conditions[] = \sprintf(
                'NOT EXISTS (SELECT 1 FROM %s.%s r WHERE r.%s = f.id)',
                $this->connection->quoteSingleIdentifier($reference['schema']),
                $this->connection->quoteSingleIdentifier($reference['table']),
                $this->connection->quoteSingleIdentifier($reference['column']),
            );
        }

        return [] === $conditions ? 'TRUE' : implode(' AND ', $conditions);
    }

    /**
     * Everything in the current tenant's directory of the storage (StorageKeys). A key the
     * storage did not get from store() or from the thumbnailer is not ours: it is left alone. So
     * are the other tenants' directories, and the keys of before tenants had directories
     * (tenant:files:move takes care of those).
     *
     * @return array{array<string, int>, array<string, list<string>>} the contents (key => size), the thumbnails (SHA-256 => keys)
     */
    private function storedContents(): array
    {
        $contents = [];
        $thumbnails = [];
        foreach ($this->storage->listContents(StorageKeys::directory($this->tenant->id()), true) as $item) {
            if (!$item instanceof FileAttributes) {
                continue;
            }
            if (1 === preg_match(StorageKeys::CONTENT, $item->path())) {
                $contents[$item->path()] = $item->fileSize() ?? 0;
            } elseif (1 === preg_match(StorageKeys::THUMBNAIL, $item->path(), $match)) {
                $thumbnails[$match[1]][] = $item->path();
            }
        }

        return [$contents, $thumbnails];
    }

    /**
     * @param list<string> $keys
     * @param list<string> $ignoredIds rows to count as gone
     *
     * @return array<string, true> the content keys some row is stored under
     */
    private function heldKeys(array $keys, array $ignoredIds): array
    {
        return $this->held('storage_key', $keys, $ignoredIds);
    }

    /**
     * @param list<string> $hashes
     * @param list<string> $ignoredIds rows to count as gone
     *
     * @return array<string, true> the hashes some row of the current tenant has: their
     *                             thumbnails are made from its content
     */
    private function heldHashes(array $hashes, array $ignoredIds): array
    {
        return $this->held('sha256', $hashes, $ignoredIds);
    }

    /**
     * @param 'storage_key'|'sha256' $column
     * @param list<string>           $values
     * @param list<string>           $ignoredIds
     *
     * @return array<string, true>
     */
    private function held(string $column, array $values, array $ignoredIds): array
    {
        $held = [];
        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            $sql = \sprintf('SELECT DISTINCT %1$s FROM core.file WHERE tenant_id = %2$s AND %1$s IN (:values)', $column, TenantScope::CURRENT);
            $parameters = ['values' => $chunk];
            if ([] !== $ignoredIds) {
                $sql .= ' AND id NOT IN (:ignored)';
                $parameters['ignored'] = $ignoredIds;
            }
            /** @var list<string> $inUse */
            $inUse = $this->connection->fetchFirstColumn($sql, $parameters, ['values' => ArrayParameterType::STRING, 'ignored' => ArrayParameterType::STRING]);
            foreach ($inUse as $value) {
                $held[$value] = true;
            }
        }

        return $held;
    }

    /**
     * Deletes the bytes of a content under its lock, once sure no row uses it: a store() of
     * the same bytes, its transaction still open, is waited for.
     *
     * @return bool false when a row uses the content after all
     */
    private function deleteContent(string $key): bool
    {
        return $this->connection->transactional(function () use ($key): bool {
            ContentLock::acquire($this->connection, $key);
            if (false !== $this->connection->fetchOne('SELECT 1 FROM core.file WHERE storage_key = ? LIMIT 1', [$key])) {
                return false;
            }
            $this->storage->delete($key);

            return true;
        });
    }
}
