<?php

declare(strict_types=1);

namespace App\Module\Record\Domain\Repository;

interface FileReferences
{
    /**
     * The objects with a record that holds the file in one of its file fields. Records in the
     * bin and archived fields do not count: nobody may read the file through them.
     *
     * @return list<string> object names
     */
    public function objectsHolding(string $fileId): array;
}
