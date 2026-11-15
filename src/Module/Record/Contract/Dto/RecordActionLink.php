<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Dto;

/**
 * Something another module offers to do on a record, shown on its page: a link, or a button
 * posting a form (its name given) with nothing but its token.
 */
final readonly class RecordActionLink
{
    public function __construct(
        public string $label,
        public string $url,
        /** The name of the form to post, its token field included; null for a link. */
        public ?string $formName = null,
    ) {
    }
}
