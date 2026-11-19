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
    case Transition = 'transition';
    /** Runs another workflow, on a called trigger, inside this run: its output, what it returns. */
    case RunWorkflow = 'run_workflow';
    /** A called workflow's end: the values its caller's run_workflow step outputs. */
    case Return = 'return';
    /** An email to members and to the addresses of a record's email fields, once the run's writes are kept. */
    case SendEmail = 'send_email';
    /** A notification to members (the bell, and their email if they chose it), once the run's writes are kept. */
    case Notify = 'notify';

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
        return $this->isPlatform() || \in_array($this, [self::If, self::Filter, self::Refuse, self::HttpRequest, self::SendEmail], true);
    }

    /**
     * Whether the step writes records: it needs a permission, and may act as a role.
     */
    public function writes(): bool
    {
        return \in_array($this, [self::CreateRecord, self::UpdateRecord, self::DeleteRecord, self::UpsertRecord, self::Transition], true);
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
            self::Transition => ['object' => true, 'id' => true, 'field' => true, 'name' => true, ...$writing],
            self::RunWorkflow => ['workflow' => true, 'input' => false],
            self::Return => ['values' => false],
            self::SendEmail => ['to' => true, 'subject' => true, 'body' => true],
            self::Notify => ['to' => true, 'title' => true, 'text' => false, 'link' => false],
            self::HttpRequest => ['url' => true, 'method' => false, 'headers' => false, 'body' => false, 'timeout' => false, 'retries' => false, 'fail_on_error' => false],
        };
    }
}
