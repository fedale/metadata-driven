<?php

declare(strict_types=1);

namespace App\Module\Template\Application;

/**
 * What an install did, or, with dryRun, would have done before it was rolled back.
 */
final readonly class InstallReport
{
    /**
     * @param list<string> $objects        the objects created
     * @param list<string> $forms          the composite forms saved
     * @param list<string> $skippedLocales languages of the template this instance does not have
     */
    public function __construct(
        public string $name,
        public string $version,
        public bool $dryRun,
        public array $objects,
        public int $fields,
        public int $views,
        public array $forms,
        public int $translations,
        public int $records,
        public array $skippedLocales,
    ) {
    }
}
