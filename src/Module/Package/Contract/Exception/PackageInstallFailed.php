<?php

declare(strict_types=1);

namespace App\Module\Package\Contract\Exception;

/**
 * A package could not be installed, nothing of it kept: not found in the indexes, invalid, or
 * refused (installed already, a name taken). The reasons are in the message.
 */
final class PackageInstallFailed extends PackageException
{
}
