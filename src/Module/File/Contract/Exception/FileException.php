<?php

declare(strict_types=1);

namespace App\Module\File\Contract\Exception;

/**
 * Base class for every error raised by the File module; its message is safe to show to users.
 */
abstract class FileException extends \DomainException
{
}
