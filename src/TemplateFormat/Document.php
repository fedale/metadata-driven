<?php

declare(strict_types=1);

namespace App\TemplateFormat;

/**
 * One document of a package: the envelope (format, kind, id) and the item it describes.
 */
final readonly class Document
{
    /**
     * @param array<string, mixed> $spec the item, as its kind's schema describes it
     * @param string               $path where it is in the package, e.g. "objects/product.yaml"
     */
    public function __construct(
        public DocumentKind $kind,
        public string $id,
        public array $spec,
        public string $path,
    ) {
    }
}
