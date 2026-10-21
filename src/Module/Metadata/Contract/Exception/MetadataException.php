<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Exception;

/**
 * Base class for every error raised by the Metadata module; its message is safe to show to users.
 */
abstract class MetadataException extends \DomainException
{
}
