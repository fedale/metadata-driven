<?php

declare(strict_types=1);

namespace App\Module\User\Contract\Exception;

/**
 * Base class for every error raised by the User module; its message is safe to show to users.
 */
abstract class UserException extends \DomainException
{
}
