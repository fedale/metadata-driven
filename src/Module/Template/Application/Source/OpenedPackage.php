<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Source;

use Fedale\TemplateFormat\Package;

final readonly class OpenedPackage
{
    public function __construct(
        public Package $package,
        /** Recorded with the installation: the directory, or the address of the index. */
        public string $source,
        /** The temporary directory it was downloaded into, removed by PackageLocator::close(). */
        public ?string $download,
    ) {
    }
}
