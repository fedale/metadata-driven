<?php

declare(strict_types=1);

namespace App\Module\File\Infrastructure\Imagine;

use App\Module\File\Contract\Dto\Thumbnail;
use App\Module\File\Contract\Dto\ThumbnailSize;
use App\Module\File\Contract\Exception\FileNotFound;
use App\Module\File\Contract\ThumbnailerInterface;
use App\Module\File\Domain\Repository\FileRepository;
use Imagine\Exception\RuntimeException as ImagineException;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ManipulatorInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Uid\Uuid;

/**
 * Thumbnails with GD, kept in the file storage under thumbnails/<size>/<sha256>.<format>: the
 * same bytes share them, and a stored file never changes, so a thumbnail never goes stale.
 */
final class ImagineThumbnailer implements ThumbnailerInterface
{
    /** The format of the thumbnail of each image type: a GIF becomes a PNG of its first frame. */
    private const array FORMATS = ['image/jpeg' => 'jpeg', 'image/png' => 'png', 'image/gif' => 'png', 'image/webp' => 'webp'];
    // GD decodes the whole bitmap, about 4 bytes a pixel: 40 megapixels is some 160 MB. A
    // larger image (or a small file that decompresses to a huge one) gets no thumbnail.
    private const int MAX_PIXELS = 40_000_000;

    public function __construct(
        #[Target('record_files.storage')]
        private readonly FilesystemOperator $storage,
        private readonly FileRepository $files,
    ) {
    }

    public function thumbnail(string $id, ThumbnailSize $size): ?Thumbnail
    {
        $file = (Uuid::isValid($id) ? $this->files->find(Uuid::fromString($id)) : null) ?? throw FileNotFound::withId($id);
        $format = self::FORMATS[$file->getMimeType()] ?? null;
        $width = $file->getWidth();
        $height = $file->getHeight();
        if (null === $format || null === $width || null === $height || $width * $height > self::MAX_PIXELS) {
            return null;
        }

        $mimeType = 'image/'.$format;
        $key = \sprintf('thumbnails/%s/%s.%s', $size->value, $file->getSha256(), $format);
        try {
            if ($this->storage->fileExists($key)) {
                return new Thumbnail($mimeType, $this->storage->read($key));
            }
            $original = $this->storage->read($file->getStorageKey());
        } catch (FilesystemException $e) {
            throw FileNotFound::bytesMissing($id, $e);
        }

        try {
            // INSET without the upscale flag: fits the box, never grows a small image.
            $bytes = (new Imagine())->load($original)
                ->thumbnail(new Box($size->pixels(), $size->pixels()), ManipulatorInterface::THUMBNAIL_INSET)
                ->get($format, ['jpeg_quality' => 85, 'webp_quality' => 85, 'png_compression_level' => 9]);
        } catch (ImagineException) {
            // Detected as an image, yet GD cannot decode it (a truncated file): no thumbnail,
            // the file itself is still served.
            return null;
        }
        $this->storage->write($key, $bytes);

        return new Thumbnail($mimeType, $bytes);
    }
}
