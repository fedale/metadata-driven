<?php

declare(strict_types=1);

namespace App\Module\File\Infrastructure\Dbal;

use App\Module\File\Application\Dto\CollectedFile;
use App\Module\File\Application\Dto\GarbageReport;
use App\Module\File\Application\FileGarbageCollectorInterface;
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
    private const string CONTENT_KEY = '~^[0-9a-f]{2}/([0-9a-f]{64})$~';
    private const string THUMBNAIL_KEY = '~^thumbnails/[a-z]+/([0-9a-f]{64})\.[a-z]+$~';

    public function __construct(
        private readonly Connection $connection,
        #[Target('record_files.storage')]
        private readonly FilesystemOperator $storage,
    ) {
    }

    public function collect(\DateTimeImmutable $storedBefore, bool $dryRun = false): GarbageReport
    {
        $files = $this->unreferencedFiles($storedBefore, $dryRun);
        // On a dry run the rows are still there: their contents count as freed unless another
        // row uses them.
        $ignoredIds = $dryRun ? array_map(static fn (CollectedFile $file): string => $file->id, $files) : [];

        [$contents, $thumbnails] = $this->storedContents();
        $held = $this->heldHashes(array_keys($contents + $thumbnails), $ignoredIds);
        $freedContents = array_diff_key($contents, $held);
        $freedThumbnails = array_diff_key($thumbnails, $held);

        $contentCount = 0;
        $contentBytes = 0;
        foreach ($freedContents as $sha256 => [$key, $size]) {
            if ($dryRun || $this->deleteContent($sha256, $key)) {
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
     * The rows referenced by nothing, deleted unless on a dry run. The DELETE repeats the
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
            \sprintf('SELECT f.id, f.original_name, f.size, f.created_at FROM core.file f WHERE f.created_at < :before AND %s ORDER BY f.created_at, f.id', $unreferenced),
            ['before' => $storedBefore],
            ['before' => Types::DATETIME_IMMUTABLE],
        );
        if (!$dryRun && [] !== $rows) {
            $deleted = [];
            foreach (array_chunk(array_column($rows, 'id'), self::CHUNK) as $ids) {
                $deleted = [...$deleted, ...$this->connection->fetchFirstColumn(
                    \sprintf('DELETE FROM core.file f WHERE f.id IN (:ids) AND %s RETURNING f.id', $unreferenced),
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
     * Everything in the storage, by SHA-256. A key the storage did not get from store() or from
     * the thumbnailer is not ours: it is left alone.
     *
     * @return array{array<string, array{string, int}>, array<string, list<string>>} the contents (key, size), the thumbnails (keys)
     */
    private function storedContents(): array
    {
        $contents = [];
        $thumbnails = [];
        foreach ($this->storage->listContents('', true) as $item) {
            if (!$item instanceof FileAttributes) {
                continue;
            }
            if (1 === preg_match(self::CONTENT_KEY, $item->path(), $match)) {
                $contents[$match[1]] = [$item->path(), $item->fileSize() ?? 0];
            } elseif (1 === preg_match(self::THUMBNAIL_KEY, $item->path(), $match)) {
                $thumbnails[$match[1]][] = $item->path();
            }
        }

        return [$contents, $thumbnails];
    }

    /**
     * @param list<string> $hashes
     * @param list<string> $ignoredIds rows to count as gone
     *
     * @return array<string, true> the hashes some row uses
     */
    private function heldHashes(array $hashes, array $ignoredIds): array
    {
        $held = [];
        foreach (array_chunk($hashes, self::CHUNK) as $chunk) {
            $sql = 'SELECT DISTINCT sha256 FROM core.file WHERE sha256 IN (:hashes)';
            $parameters = ['hashes' => $chunk];
            if ([] !== $ignoredIds) {
                $sql .= ' AND id NOT IN (:ignored)';
                $parameters['ignored'] = $ignoredIds;
            }
            /** @var list<string> $hashesInUse */
            $hashesInUse = $this->connection->fetchFirstColumn($sql, $parameters, ['hashes' => ArrayParameterType::STRING, 'ignored' => ArrayParameterType::STRING]);
            foreach ($hashesInUse as $sha256) {
                $held[$sha256] = true;
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
    private function deleteContent(string $sha256, string $key): bool
    {
        return $this->connection->transactional(function () use ($sha256, $key): bool {
            ContentLock::acquire($this->connection, $sha256);
            if (false !== $this->connection->fetchOne('SELECT 1 FROM core.file WHERE sha256 = ? LIMIT 1', [$sha256])) {
                return false;
            }
            $this->storage->delete($key);

            return true;
        });
    }
}
