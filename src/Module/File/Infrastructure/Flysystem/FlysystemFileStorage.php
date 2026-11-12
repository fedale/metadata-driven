<?php

declare(strict_types=1);

namespace App\Module\File\Infrastructure\Flysystem;

use App\Module\File\Contract\Dto\StoredFile;
use App\Module\File\Contract\Exception\FileNotFound;
use App\Module\File\Contract\FileStorageInterface;
use App\Module\File\Domain\Exception\InvalidFile;
use App\Module\File\Domain\Model\File;
use App\Module\File\Domain\Repository\FileRepository;
use App\Module\File\Infrastructure\StorageKeys;
use App\Shared\Tenant\CurrentTenant;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Mime\MimeTypesInterface;
use Symfony\Component\Uid\Uuid;

final class FlysystemFileStorage implements FileStorageInterface
{
    private const int MAX_NAME_LENGTH = 255;

    public function __construct(
        #[Target('record_files.storage')]
        private readonly FilesystemOperator $storage,
        private readonly FileRepository $files,
        private readonly MimeTypesInterface $mimeTypes,
        private readonly CurrentTenant $tenant,
    ) {
    }

    public function store(string $path, string $originalName): StoredFile
    {
        $originalName = self::cleanName($originalName);
        $sha256 = is_file($path) && is_readable($path) ? hash_file('sha256', $path) : false;
        $size = false !== $sha256 ? filesize($path) : false;
        if (false === $sha256 || false === $size) {
            throw InvalidFile::unreadable($originalName);
        }

        $mimeType = $this->mimeTypes->guessMimeType($path) ?? 'application/octet-stream';
        [$width, $height] = self::dimensions($path, $mimeType);

        // The key is the tenant and the content, never the name: no path from the outside
        // reaches the storage, and the same bytes uploaded twice to a tenant are stored once
        // (StorageKeys). The bytes and the row are written under the lock of the content, so
        // the GC cannot delete bytes found here before the row is visible.
        $key = StorageKeys::content($this->tenant->id(), $sha256);
        $file = $this->files->underContentLock($key, function () use ($path, $key, $originalName, $mimeType, $size, $sha256, $width, $height): File {
            if (!$this->storage->fileExists($key)) {
                $stream = fopen($path, 'r');
                if (false === $stream) {
                    throw InvalidFile::unreadable($originalName);
                }
                try {
                    $this->storage->writeStream($key, $stream);
                } finally {
                    fclose($stream);
                }
            }
            $file = new File(Uuid::v7(), $key, $originalName, $mimeType, $size, $sha256, $width, $height, new \DateTimeImmutable());
            $this->files->save($file);

            return $file;
        });

        return self::toDto($file);
    }

    public function find(string $id): ?StoredFile
    {
        $file = Uuid::isValid($id) ? $this->files->find(Uuid::fromString($id)) : null;

        return null !== $file ? self::toDto($file) : null;
    }

    public function findMany(array $ids): array
    {
        $uuids = [];
        foreach ($ids as $id) {
            if (Uuid::isValid($id)) {
                $uuids[strtolower($id)] = Uuid::fromString($id);
            }
        }
        $found = [];
        foreach ($this->files->findMany(array_values($uuids)) as $file) {
            $found[$file->getId()->toRfc4122()] = self::toDto($file);
        }

        return array_intersect_key($found, $uuids);
    }

    public function readStream(string $id)
    {
        $file = (Uuid::isValid($id) ? $this->files->find(Uuid::fromString($id)) : null) ?? throw FileNotFound::withId($id);

        try {
            return $this->storage->readStream($file->getStorageKey());
        } catch (FilesystemException $e) {
            throw FileNotFound::bytesMissing($id, $e);
        }
    }

    /**
     * The base name, without control characters: a name like "../../etc/passwd" or one with a
     * newline never reaches a Content-Disposition header.
     */
    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));
        $name = mb_substr($name, 0, self::MAX_NAME_LENGTH);

        return '' === $name || '.' === $name || '..' === $name ? 'file' : $name;
    }

    /**
     * @return array{?int, ?int} in pixels; null for anything but a raster image
     */
    private static function dimensions(string $path, string $mimeType): array
    {
        if (!str_starts_with($mimeType, 'image/') || 'image/svg+xml' === $mimeType) {
            return [null, null];
        }
        // A truncated image makes getimagesize() warn before returning false: false is the
        // answer we need, so the warning is suppressed rather than turned into an error.
        $size = @getimagesize($path);

        return false !== $size && $size[0] > 0 ? [$size[0], $size[1]] : [null, null];
    }

    private static function toDto(File $file): StoredFile
    {
        return new StoredFile(
            $file->getId()->toRfc4122(),
            $file->getOriginalName(),
            $file->getMimeType(),
            $file->getSize(),
            $file->getSha256(),
            $file->getWidth(),
            $file->getHeight(),
            $file->getCreatedAt(),
        );
    }
}
