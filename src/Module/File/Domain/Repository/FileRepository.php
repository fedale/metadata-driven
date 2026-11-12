<?php

declare(strict_types=1);

namespace App\Module\File\Domain\Repository;

use App\Module\File\Domain\Model\File;
use Symfony\Component\Uid\Uuid;

interface FileRepository
{
    public function save(File $file): void;

    /**
     * Runs $write in a transaction holding the lock of a content, named by its storage key (a
     * tenant's content: the same bytes in two tenants are two contents), until the outermost
     * transaction ends, so the GC never deletes bytes a new row is about to use.
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    public function underContentLock(string $storageKey, callable $write): mixed;

    public function find(Uuid $id): ?File;

    /**
     * @param list<Uuid> $ids
     *
     * @return list<File> the files found, in no particular order
     */
    public function findMany(array $ids): array;
}
