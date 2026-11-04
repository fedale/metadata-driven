<?php

declare(strict_types=1);

namespace App\Module\File\Contract;

use App\Module\File\Contract\Dto\StoredFile;
use App\Module\File\Contract\Exception\FileException;
use App\Module\File\Contract\Exception\FileNotFound;

/**
 * The files of the records: their bytes in the "record_files" storage, their metadata in
 * core.file. A stored file is never changed; replacing a file stores a new one.
 */
interface FileStorageInterface
{
    /**
     * Stores a local file (an upload, a file of a package). Its type is detected from its
     * content, never from its name or extension.
     *
     * @param string $path         a readable local file, left in place
     * @param string $originalName the name shown and offered on download; any path is dropped
     *
     * @throws FileException the file cannot be read
     */
    public function store(string $path, string $originalName): StoredFile;

    /**
     * @return StoredFile|null null for an unknown or malformed id
     */
    public function find(string $id): ?StoredFile;

    /**
     * Several files in one query, for a page of records.
     *
     * @param list<string> $ids malformed and unknown ids are skipped
     *
     * @return array<string, StoredFile> by id, as the ids were given (lowercase RFC 4122)
     */
    public function findMany(array $ids): array;

    /**
     * @return resource the bytes of the file, to be closed by the caller
     *
     * @throws FileNotFound unknown id, or bytes missing from the storage
     */
    public function readStream(string $id);
}
