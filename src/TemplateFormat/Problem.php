<?php

declare(strict_types=1);

namespace App\TemplateFormat;

/**
 * Something wrong in a package, located by its document and, inside it, by a path of keys.
 */
final readonly class Problem implements \Stringable
{
    public function __construct(
        /** The document, e.g. "objects/product.yaml"; empty for the package as a whole. */
        public string $path,
        /** Inside the document, e.g. "spec.fields.2.settings"; empty for the document. */
        public string $at,
        public string $message,
    ) {
    }

    public function __toString(): string
    {
        return implode(': ', array_filter([$this->path, $this->at, $this->message], static fn (string $part): bool => '' !== $part));
    }
}
