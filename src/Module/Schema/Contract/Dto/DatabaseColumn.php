<?php

declare(strict_types=1);

namespace App\Module\Schema\Contract\Dto;

/**
 * A column of a table loaded into the data schema, as the catalog describes it.
 */
final readonly class DatabaseColumn
{
    public function __construct(
        public string $name,
        // As PostgreSQL writes it: "character varying(80)", "numeric(10,2)", "integer".
        public string $type,
        public bool $nullable,
        // The SQL expression of its default, if any: "nextval('t_id_seq'::regclass)", "0".
        public ?string $default,
        // An identity column (GENERATED ... AS IDENTITY), numbered by the database.
        public bool $identity,
    ) {
    }
}
