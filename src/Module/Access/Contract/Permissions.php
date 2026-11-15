<?php

declare(strict_types=1);

namespace App\Module\Access\Contract;

/**
 * Names of the RBAC items the application checks.
 */
final class Permissions
{
    /** The admin UI: objects, fields, search, tables. */
    public const string ADMINISTER = 'admin.metadata';

    /** The members of the workspace: who may sign in, with which roles (/admin/members). */
    public const string MEMBERS = 'admin.members';

    /** The roles of the workspace: which permissions each gives (/admin/roles). */
    public const string ROLES = 'admin.roles';

    /** The workflows of the workspace, their versions and their runs (/admin/workflows). */
    public const string WORKFLOWS = 'admin.workflows';

    /** Short-circuits every check (fedale_rbac.super_admin_role). */
    public const string SUPER_ADMIN_ROLE = 'ROLE_SUPER_ADMIN';

    public static function record(string $objectName, RecordAction $action): string
    {
        return \sprintf('record.%s.%s', $objectName, $action->value);
    }

    /**
     * Running a manual workflow: created when a manual version of it is activated.
     */
    public static function workflowRun(string $workflowId): string
    {
        return \sprintf('workflow.%s.run', $workflowId);
    }

    /**
     * The workflow id of a workflow permission (workflow.<id>.run), null for the others.
     */
    public static function workflowOf(string $permission): ?string
    {
        return 1 === preg_match('/^workflow\.([0-9a-f-]{36})\.run$/', $permission, $match) ? $match[1] : null;
    }
}
