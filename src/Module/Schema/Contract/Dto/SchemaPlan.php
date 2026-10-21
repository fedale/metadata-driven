<?php

declare(strict_types=1);

namespace App\Module\Schema\Contract\Dto;

/**
 * DDL needed to align the physical table of one object with its metadata.
 */
final readonly class SchemaPlan
{
    /**
     * @param list<string> $statements SQL statements, in execution order
     * @param list<string> $skipped    destructive changes that were detected but not planned
     */
    public function __construct(
        public string $objectName,
        public string $tableName,
        public bool $createsTable,
        public array $statements,
        public array $skipped,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->statements;
    }
}
