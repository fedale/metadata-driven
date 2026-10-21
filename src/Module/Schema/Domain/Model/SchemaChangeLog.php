<?php

declare(strict_types=1);

namespace App\Module\Schema\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * Audit entry of the DDL applied to a record table.
 */
class SchemaChangeLog
{
    private \DateTimeImmutable $appliedAt;

    /**
     * @param list<string> $statements
     * @param list<string> $skipped
     */
    public function __construct(
        private Uuid $id,
        private string $objectName,
        private string $tableName,
        private array $statements,
        private array $skipped,
    ) {
        $this->appliedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getObjectName(): string
    {
        return $this->objectName;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * @return list<string>
     */
    public function getStatements(): array
    {
        return $this->statements;
    }

    /**
     * @return list<string>
     */
    public function getSkipped(): array
    {
        return $this->skipped;
    }

    public function getAppliedAt(): \DateTimeImmutable
    {
        return $this->appliedAt;
    }
}
