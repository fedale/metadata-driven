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
    case InstallPackage = 'install_package';
    case AssignRole = 'assign_role';
    case Wait = 'wait';
    case HttpRequest = 'http_request';

    /**
     * Whether the step is the platform's: only a system workflow takes it, and takes no other
     * step but the conditions and refuse.
     */
    public function isPlatform(): bool
    {
        return \in_array($this, [self::InstallPackage, self::AssignRole], true);
    }

    /**
     * Whether a system workflow (on a platform event) may take the step.
     */
    public function forPlatform(): bool
    {
        return $this->isPlatform() || \in_array($this, [self::If, self::Filter, self::Refuse, self::HttpRequest], true);
    }

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
            self::InstallPackage => ['package' => true, 'repo' => false],
            self::AssignRole => ['email' => true, 'role' => true],
            self::Wait => ['for' => false, 'until' => false],
            self::HttpRequest => ['url' => true, 'method' => false, 'headers' => false, 'body' => false, 'timeout' => false, 'retries' => false, 'fail_on_error' => false],
        };
    }
}
