<?php

declare(strict_types=1);

namespace App\Module\Package\Domain\Exception;

use App\Module\Package\Contract\Exception\PackageException;

/**
 * An index or an archive that could not be found, fetched or trusted.
 */
final class PackageSourceFailed extends PackageException
{
}
