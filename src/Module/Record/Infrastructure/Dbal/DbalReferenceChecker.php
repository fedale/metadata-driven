<?php

declare(strict_types=1);

namespace App\Module\Record\Infrastructure\Dbal;

use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Validation\Contract\ReferenceCheckerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final class DbalReferenceChecker implements ReferenceCheckerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RecordTable $table,
    ) {
    }

    public function exists(ObjectDefinitionDto $target, string $id): bool
    {
        $sql = \sprintf(
            'SELECT 1 FROM %s WHERE %s = ? AND %s IS NULL',
            $this->table->table($target),
            $this->table->column('id'),
            $this->table->column('deleted_at'),
        );

        return false !== $this->connection->fetchOne($sql, [$id], [Types::GUID]);
    }
}
