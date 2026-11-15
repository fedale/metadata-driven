<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Exception;

/**
 * A search that cannot run: a field that is not there or cannot be searched on, an operator, a
 * value that does not fit the field. Raised before any query.
 */
final class InvalidRecordSearch extends \DomainException
{
}
