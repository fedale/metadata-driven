<?php

declare(strict_types=1);

namespace App\Module\File\Infrastructure\Flysystem;

use App\Module\File\Application\LegacyFileMoverInterface;
use App\Module\File\Infrastructure\StorageKeys;
use Doctrine\DBAL\Connection;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Moves the files stored before tenants had directories (their key at the root of the storage)
 * into their tenant's directory, once: tenant:files:move.
 *
 * Each row gets a copy of its bytes under its tenant (two tenants holding the same bytes get
 * one each), then its new key. Then the root contents no row uses any more are deleted, and the
 * thumbnails of the root, which are made again under the tenants on demand. Run in the
 * landlord's session: every tenant's rows.
 */
final class LegacyFileMover implements LegacyFileMoverInterface
{
    public function __construct(
        private readonly Connection $connection,
        #[Target('record_files.storage')]
        private readonly FilesystemOperator $storage,
    ) {
    }

    /**
     * @return array{moved: int, missing: list<string>, deleted: int, thumbnails: bool} the rows
     *                                                                                  moved, the ids of those whose bytes are missing, the root contents deleted, whether
     *                                                                                  there were root thumbnails (deleted)
     */
    public function move(bool $dryRun = false): array
    {
        /** @var list<array{id: string, tenant_id: string, storage_key: string, sha256: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, tenant_id, storage_key, sha256 FROM core.file WHERE storage_key !~ '^[0-9a-f-]{36}/' ORDER BY created_at, id",
        );

        $moved = 0;
        $missing = [];
        foreach ($rows as $row) {
            $key = StorageKeys::content($row['tenant_id'], $row['sha256']);
            if (!$this->storage->fileExists($key)) {
                if (!$this->storage->fileExists($row['storage_key'])) {
                    $missing[] = $row['id'];

                    continue;
                }
                if (!$dryRun) {
                    $this->storage->copy($row['storage_key'], $key);
                }
            }
            if (!$dryRun) {
                $this->connection->executeStatement('UPDATE core.file SET storage_key = ? WHERE id = ?', [$key, $row['id']]);
            }
            ++$moved;
        }

        // Once moved, no row names a root key: the rows left there have no bytes (missing).
        // On a dry run they still do, but would not after: every root content counts as freed.
        $deleted = 0;
        foreach ($this->rootContents() as $key) {
            if (!$dryRun) {
                if (false !== $this->connection->fetchOne('SELECT 1 FROM core.file WHERE storage_key = ? LIMIT 1', [$key])) {
                    continue;
                }
                $this->storage->delete($key);
            }
            ++$deleted;
        }

        $thumbnails = $this->storage->directoryExists(StorageKeys::LEGACY_THUMBNAILS);
        if ($thumbnails && !$dryRun) {
            $this->storage->deleteDirectory(StorageKeys::LEGACY_THUMBNAILS);
        }

        return ['moved' => $moved, 'missing' => $missing, 'deleted' => $deleted, 'thumbnails' => $thumbnails];
    }

    /**
     * The contents at the root of the storage: <aa>/<SHA-256>, in the two-character directories.
     *
     * @return list<string>
     */
    private function rootContents(): array
    {
        $keys = [];
        foreach ($this->storage->listContents('', false) as $directory) {
            if (!$directory instanceof DirectoryAttributes || 1 !== preg_match('~^[0-9a-f]{2}$~', $directory->path())) {
                continue;
            }
            foreach ($this->storage->listContents($directory->path(), false) as $item) {
                if ($item instanceof FileAttributes && 1 === preg_match(StorageKeys::LEGACY_CONTENT, $item->path())) {
                    $keys[] = $item->path();
                }
            }
        }

        return $keys;
    }
}
