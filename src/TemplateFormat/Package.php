<?php

declare(strict_types=1);

namespace App\TemplateFormat;

/**
 * A template: its manifest and its documents.
 */
final readonly class Package
{
    /**
     * @param list<Document> $documents every document but the manifest
     */
    public function __construct(
        public Document $manifest,
        public array $documents,
    ) {
    }

    public function name(): string
    {
        return $this->manifest->id;
    }

    public function version(): string
    {
        $version = $this->manifest->spec['version'] ?? null;

        return \is_string($version) ? $version : '';
    }

    /**
     * @return list<Document>
     */
    public function documentsOf(DocumentKind $kind): array
    {
        return array_values(array_filter($this->documents, static fn (Document $document): bool => $document->kind === $kind));
    }
}
