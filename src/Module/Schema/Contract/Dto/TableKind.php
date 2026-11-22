<?php

declare(strict_types=1);

namespace App\Module\Schema\Contract\Dto;

use App\Shared\Message\Message;

/**
 * What a relation of the data schema is, as PostgreSQL tells it (pg_class.relkind). Only a plain
 * table can become an object.
 */
enum TableKind: string
{
    case Table = 'table';
    case Partitioned = 'partitioned table';
    case View = 'view';
    case MaterializedView = 'materialized view';
    case Foreign = 'foreign table';

    public static function fromRelkind(string $relkind): self
    {
        return match ($relkind) {
            'p' => self::Partitioned,
            'v' => self::View,
            'm' => self::MaterializedView,
            'f' => self::Foreign,
            default => self::Table,
        };
    }

    /**
     * What it is, in words a user reads.
     */
    public function message(): Message
    {
        return match ($this) {
            self::Table => new Message('schema.table.kind.table', 'table'),
            self::Partitioned => new Message('schema.table.kind.partitioned', 'partitioned table'),
            self::View => new Message('schema.table.kind.view', 'view'),
            self::MaterializedView => new Message('schema.table.kind.materialized_view', 'materialized view'),
            self::Foreign => new Message('schema.table.kind.foreign', 'foreign table'),
        };
    }
}
