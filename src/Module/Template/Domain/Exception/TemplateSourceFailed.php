<?php

declare(strict_types=1);

namespace App\Module\Template\Domain\Exception;

use App\Module\Template\Contract\Exception\TemplateException;

/**
 * An index or an archive that could not be found, fetched or trusted.
 */
final class TemplateSourceFailed extends TemplateException
{
}
