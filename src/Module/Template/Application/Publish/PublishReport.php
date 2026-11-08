<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Publish;

/**
 * A template version written into a checkout of the catalog (or checked only, on a dry run).
 */
final readonly class PublishReport
{
    public function __construct(
        public string $name,
        public string $version,
        /** Where it is in the catalog: templates/<vendor>/<name>/<version>. */
        public string $directory,
        public int $files,
        public int $bytes,
        /** The highest version the catalog had, which the number was checked against; null for a first version. */
        public ?string $previous,
        public bool $dryRun,
    ) {
    }

    /**
     * The branch to propose it from: add_<vendor>_<name>_<version>, dots and hyphens as
     * underscores.
     */
    public function branch(): string
    {
        return 'add_'.str_replace(['/', '-', '.'], '_', $this->name.'_'.$this->version);
    }
}
