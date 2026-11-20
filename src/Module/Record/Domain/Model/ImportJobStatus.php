<?php

declare(strict_types=1);

namespace App\Module\Record\Domain\Model;

/**
 * Where a background import is: its check, then its import.
 */
enum ImportJobStatus: string
{
    /** Its check queued or running. */
    case Checking = 'checking';
    /** Checked: every row would be imported; waits for Import. */
    case Checked = 'checked';
    /** The check found rows refused, or the file could not be read: nothing was written. */
    case Refused = 'refused';
    /** Its import queued or running, batch by batch. */
    case Importing = 'importing';
    case Imported = 'imported';
    /** A batch was refused at import time: the batches before it stay written. */
    case Stopped = 'stopped';
    /** Stopped by its creator: an import keeps the batches it wrote. */
    case Cancelled = 'cancelled';
    /** An error outside the rows, the worker's retries spent. */
    case Failed = 'failed';

    /**
     * The worker has it, or will: the page refreshes, Stop is offered.
     */
    public function isRunning(): bool
    {
        return self::Checking === $this || self::Importing === $this;
    }

    /**
     * Done with its file: nothing more can be done with it.
     */
    public function isFinal(): bool
    {
        return \in_array($this, [self::Imported, self::Stopped, self::Cancelled, self::Failed], true);
    }
}
