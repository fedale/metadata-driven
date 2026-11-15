<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Definition;

use App\Module\Record\Contract\Event\RecordWriteAction;

/**
 * What happens to a record that starts a workflow.
 */
enum TriggerEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case CreatedOrUpdated = 'created_or_updated';
    case Deleted = 'deleted';

    public function matches(RecordWriteAction $action): bool
    {
        return match ($this) {
            self::Created => RecordWriteAction::Create === $action,
            self::Updated => RecordWriteAction::Update === $action,
            self::CreatedOrUpdated => RecordWriteAction::Delete !== $action,
            self::Deleted => RecordWriteAction::Delete === $action,
        };
    }
}
