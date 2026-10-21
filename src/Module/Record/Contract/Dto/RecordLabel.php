<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Dto;

/**
 * A record as shown where it is referenced: its id and its label.
 */
final readonly class RecordLabel
{
    public function __construct(
        public string $id,
        public string $label,
    ) {
    }
}
