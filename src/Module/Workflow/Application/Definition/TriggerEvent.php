<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Definition;

use App\Module\Record\Contract\Event\RecordWriteAction;

/**
 * What starts a workflow: something happening to a record, someone, or the platform (the system
 * admin's workflows).
 */
enum TriggerEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case CreatedOrUpdated = 'created_or_updated';
    case Deleted = 'deleted';
    /** Run by someone, from a record's page or a command: no write starts it. */
    case Manual = 'manual';
    /** A Status field's transition taken: the update it makes. */
    case Transitioned = 'transitioned';
    /** A cron expression's time come (UTC): no record, no one behind it. */
    case Schedule = 'schedule';
    /** The platform's: a workspace created (the console, workspace:create). */
    case WorkspaceCreated = 'workspace_created';
    /** The platform's: a member added to a workspace. */
    case MemberAdded = 'member_added';

    /**
     * An event of the platform: the system admin's workflows run on it, in no workspace's list.
     */
    public function isPlatform(): bool
    {
        return \in_array($this, [self::WorkspaceCreated, self::MemberAdded], true);
    }

    public function matches(RecordWriteAction $action): bool
    {
        return match ($this) {
            self::Created => RecordWriteAction::Create === $action,
            self::Updated => RecordWriteAction::Update === $action,
            self::CreatedOrUpdated => RecordWriteAction::Delete !== $action,
            self::Deleted => RecordWriteAction::Delete === $action,
            self::Manual, self::WorkspaceCreated, self::MemberAdded, self::Schedule => false,
            // Its transition says (Trigger::fires()).
            self::Transitioned => RecordWriteAction::Update === $action,
        };
    }
}
