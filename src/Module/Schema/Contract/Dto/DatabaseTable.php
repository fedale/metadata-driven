<?php

declare(strict_types=1);

namespace App\Module\Schema\Contract\Dto;

use App\Module\Metadata\Contract\Dto\KeyType;
use App\Shared\Message\Message;

/**
 * A table of the workspace's data schema that no object owns: loaded into the database by hand
 * (a dump, a script), as the catalog describes it, with what adopting it as an object would
 * take.
 */
final readonly class DatabaseTable
{
    /**
     * @param list<DatabaseColumn>     $columns      in the order of the table
     * @param list<string>             $primaryKey   its columns; empty for none
     * @param list<list<string>>       $uniques      the columns of each unique constraint
     * @param list<DatabaseForeignKey> $foreignKeys
     * @param int|null                 $rows         an estimate (the planner's statistics); null when the table was
     *                                               never analysed
     * @param string|null              $proposedName the object's name proposed from the table's, a valid machine name
     *                                               no object has; null when there is none to propose
     * @param KeyType|null             $keyType      the key its primary key would give the object; null for none
     * @param list<Message>            $refusals     why it cannot be adopted; empty when it can
     */
    public function __construct(
        public string $name,
        public TableKind $kind,
        public array $columns,
        public array $primaryKey,
        public array $uniques,
        public array $foreignKeys,
        public ?int $rows,
        public ?string $proposedName,
        public ?KeyType $keyType,
        public array $refusals,
    ) {
    }

    public function isAdoptable(): bool
    {
        return [] === $this->refusals;
    }

    public function column(string $name): ?DatabaseColumn
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }
}
