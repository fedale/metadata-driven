<?php

declare(strict_types=1);

namespace App\Module\File\Contract;

use App\Module\File\Contract\Dto\Thumbnail;
use App\Module\File\Contract\Dto\ThumbnailSize;
use App\Module\File\Contract\Exception\FileNotFound;

interface ThumbnailerInterface
{
    /**
     * The image scaled down to fit the size (never up), made once and then kept in the storage.
     *
     * @return Thumbnail|null null for a file that is not a raster image, or one too large to scale
     *
     * @throws FileNotFound unknown id, or bytes missing from the storage
     */
    public function thumbnail(string $id, ThumbnailSize $size): ?Thumbnail;
}
