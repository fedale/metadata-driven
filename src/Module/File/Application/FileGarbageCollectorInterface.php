<?php

declare(strict_types=1);

namespace App\Module\File\Application;

use App\Module\File\Application\Dto\GarbageReport;

/**
 * Deletes the stored files nothing refers to any more: a file is referenced while a foreign key
 * to core.file holds its id, in a file column or in the link table of a file list, of any
 * record (deleted ones included, since they can be restored) and of any field (archived ones
 * included, since they keep their columns and tables). Purging a field or an object, unlinking
 * a file from a list, replacing or removing the file of a record, or a write failing after its
 * upload leave the files to this collector.
 */
interface FileGarbageCollectorInterface
{
    /**
     * Deletes the core.file rows referenced by nothing and stored before $storedBefore (an
     * upload whose record is not written yet is not referenced either), then the bytes and
     * thumbnails of the contents no row uses any more, stray bytes included (an upload whose
     * transaction rolled back keeps its bytes but loses its row).
     *
     * @param bool $dryRun report what would be deleted, delete nothing
     */
    public function collect(\DateTimeImmutable $storedBefore, bool $dryRun = false): GarbageReport;
}
