<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Event;

use App\Module\Record\Contract\Dto\RecordOutput;

/**
 * A record was written, and the outermost transaction committed: raised once it is, never for
 * what was rolled back. A form writing several records raises one per record, after the whole
 * form is committed; a delete's cascade, one per record deleted.
 */
final readonly class RecordWritten
{
    /**
     * @param RecordOutput      $record  as written; on delete, as it was
     * @param RecordOutput|null $before  on update, the record as it was
     * @param list<string>      $changed the fields whose value changed (every field on create and delete)
     */
    public function __construct(
        public string $objectName,
        public RecordWriteAction $action,
        public string $id,
        public RecordOutput $record,
        public ?RecordOutput $before,
        public array $changed,
        public ?RecordWriteOrigin $origin,
        /** The transition the write took, if any. */
        public ?RecordTransition $transition = null,
    ) {
    }
}
