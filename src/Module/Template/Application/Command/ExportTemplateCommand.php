<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Command;

final readonly class ExportTemplateCommand
{
    /**
     * @param list<string> $objects the objects exported, with their fields and views
     * @param list<string> $forms   the composite forms exported; their objects must be exported too
     */
    public function __construct(
        public string $name,
        public string $version,
        public array $objects,
        public array $forms = [],
        public ?string $label = null,
        public ?string $description = null,
        public string $license = 'proprietary',
    ) {
    }
}
