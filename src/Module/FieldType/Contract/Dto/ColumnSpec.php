<?php

declare(strict_types=1);

namespace App\Module\FieldType\Contract\Dto;

/**
 * Description of the physical column backing a field; translated to DDL by the Schema module.
 */
final readonly class ColumnSpec
{
    public function __construct(
        public ColumnType $type,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
    ) {
    }
}
