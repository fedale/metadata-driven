<?php

declare(strict_types=1);

namespace App\Module\Package\Application;

use App\Module\File\Contract\FileStorageInterface;
use Fedale\PackageFormat\PackageFile;

/**
 * A file a record holds, copied into an exported package when the package is written: its
 * bytes are streamed from the storage then, never held in memory.
 */
final readonly class StoredPackageFile implements PackageFile
{
    public function __construct(
        private FileStorageInterface $files,
        private string $id,
    ) {
    }

    public function open()
    {
        return $this->files->readStream($this->id);
    }
}
