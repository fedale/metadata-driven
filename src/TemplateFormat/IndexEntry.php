<?php

declare(strict_types=1);

namespace App\TemplateFormat;

/**
 * One version of one template, as an index lists it.
 */
final readonly class IndexEntry
{
    /**
     * @param array<string, string> $requires what the template requires: the format, other templates
     */
    public function __construct(
        public string $name,
        public string $version,
        /** Where the archive is: absolute, resolved against the index's address. */
        public string $url,
        public string $sha256,
        public ?string $signature = null,
        public array $requires = ['format' => Format::VERSION],
        /** Hidden from new installs and upgrades; still there for whoever names the version. */
        public bool $yanked = false,
    ) {
    }
}
