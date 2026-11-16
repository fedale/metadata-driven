<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Event;

use App\Module\Record\Contract\Dto\RecordOutput;

/**
 * A record is about to be written, inside the write's transaction. A listener may change the
 * values (they are validated afterwards, as if given so) or refuse the write: create and update
 * then fail with a RecordValidationFailed, delete with a RecordDeleteRestricted, the message
 * shown to the user.
 *
 * A delete's cascade raises one per record deleted.
 */
final class RecordWriting
{
    private ?string $refusal = null;

    /**
     * @param array<string, mixed> $values  what is about to be written, as given (raw: before validation); empty on delete
     * @param RecordOutput|null    $current the record as stored, on update and delete
     */
    public function __construct(
        public readonly string $objectName,
        public readonly RecordWriteAction $action,
        public readonly ?string $id,
        private array $values,
        public readonly ?RecordOutput $current,
        public readonly ?RecordWriteOrigin $origin,
        /** The transition the write takes, if any. */
        public readonly ?RecordTransition $transition = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return $this->values;
    }

    public function setValue(string $fieldName, mixed $value): void
    {
        if (RecordWriteAction::Delete === $this->action) {
            throw new \LogicException('A delete writes no value.');
        }
        $this->values[$fieldName] = $value;
    }

    /**
     * Refuses the write; the first refusal's message is the one shown.
     */
    public function refuse(string $message): void
    {
        $this->refusal ??= $message;
    }

    public function refusal(): ?string
    {
        return $this->refusal;
    }
}
