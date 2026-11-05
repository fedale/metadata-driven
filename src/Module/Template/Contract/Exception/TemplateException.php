<?php

declare(strict_types=1);

namespace App\Module\Template\Contract\Exception;

/**
 * Base class for every error raised by the Template module; its message is safe to show to users.
 */
abstract class TemplateException extends \DomainException
{
}
