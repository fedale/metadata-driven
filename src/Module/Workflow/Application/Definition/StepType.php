<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Definition;

enum StepType: string
{
    case If = 'if';
    case Filter = 'filter';
    case CreateRecord = 'create_record';
    case UpdateRecord = 'update_record';
    case DeleteRecord = 'delete_record';
    case UpsertRecord = 'upsert_record';
    case SetValues = 'set_values';
    case Refuse = 'refuse';
    case SearchRecords = 'search_records';
    case Iterator = 'iterator';

    /**
     * Whether the step writes records: it needs a permission, and may act as a role.
     */
    public function writes(): bool
    {
        return \in_array($this, [self::CreateRecord, self::UpdateRecord, self::DeleteRecord, self::UpsertRecord], true);
    }

    /**
     * Whether the step reads records: it needs their read permission, and may act as a role.
     */
    public function reads(): bool
    {
        return self::SearchRecords === $this;
    }

    /**
     * The options of the step, after "key" and "type".
     *
     * @return array<string, bool> option => required
     */
    public function options(): array
    {
        $writing = ['act_as' => false, 'emit_events' => false];

        return match ($this) {
            self::If => ['branches' => true],
            self::Filter => ['when' => true],
            self::CreateRecord => ['object' => true, 'values' => true, ...$writing],
            self::UpdateRecord => ['object' => true, 'id' => true, 'values' => true, ...$writing],
            self::DeleteRecord => ['object' => true, 'id' => true, ...$writing],
            self::UpsertRecord => ['object' => true, 'match' => true, 'values' => true, ...$writing],
            self::SetValues => ['values' => true],
            self::Refuse => ['message' => true],
            self::SearchRecords => ['object' => true, 'where' => false, 'sort' => false, 'direction' => false, 'limit' => false, 'act_as' => false],
            self::Iterator => ['items' => true, 'steps' => true],
        };
    }
}
