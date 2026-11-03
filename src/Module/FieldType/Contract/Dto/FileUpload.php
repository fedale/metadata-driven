<?php

declare(strict_types=1);

namespace App\Module\FieldType\Contract\Dto;

/**
 * A file given for a "file" field, not stored yet: an upload, a file of a package. The UI
 * builds it from its own upload object, so the field types stay free of the HTTP layer.
 */
final readonly class FileUpload implements \Stringable
{
    public function __construct(
        /** A readable local file. */
        public string $path,
        /** The name the file had for the user; any path in it is dropped when it is stored. */
        public string $originalName,
    ) {
    }

    /**
     * The path: Symfony's File and Image constraints check the file it names.
     */
    public function __toString(): string
    {
        return $this->path;
    }
}
