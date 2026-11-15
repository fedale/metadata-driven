<?php

declare(strict_types=1);

namespace App\Module\File\Application;

/**
 * Moves the files stored before workspaces had directories in the storage into their workspace's
 * directory: workspace:files:move.
 */
interface LegacyFileMoverInterface
{
    /**
     * In the system session: every workspace's rows.
     *
     * @return array{moved: int, missing: list<string>, deleted: int, thumbnails: bool} the rows
     *                                                                                  moved, the ids of those whose bytes are missing, the root contents deleted, whether
     *                                                                                  there were root thumbnails (deleted)
     */
    public function move(bool $dryRun = false): array;
}
