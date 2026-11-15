<?php

declare(strict_types=1);

namespace App\Module\Package\Contract\Exception;

/**
 * Base class for every error raised by the Package module; its message is safe to show to users.
 */
abstract class PackageException extends \DomainException
{
}
