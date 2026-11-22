<?php

declare(strict_types=1);

namespace App\Module\Schema\Contract;

use App\Module\Schema\Contract\Dto\DatabaseTable;

/**
 * The tables of the workspace's data schema that no object owns: neither an object's table
 * (archived ones included) nor the link table of one of their fields. Read live from the
 * catalog: a table loaded a moment ago is there, with no cache to clear.
 *
 * Only the workspace's own data schema is read: a table loaded into another workspace's, or into
 * "public", is never listed.
 */
interface DatabaseTablesInterface
{
    /**
     * @return list<DatabaseTable> by name
     */
    public function unmanaged(): array;

    /**
     * The unmanaged table of that name; null when there is none (or an object owns it).
     */
    public function find(string $name): ?DatabaseTable;
}
