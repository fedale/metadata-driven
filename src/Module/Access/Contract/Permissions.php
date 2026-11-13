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

    /** The members of the tenant: who may sign in, with which roles (/admin/members). */
    public const string MEMBERS = 'admin.members';

    /** The roles of the tenant: which permissions each gives (/admin/roles). */
    public const string ROLES = 'admin.roles';

    /** Short-circuits every check (fedale_rbac.super_admin_role). */
    public const string SUPER_ADMIN_ROLE = 'ROLE_SUPER_ADMIN';

    public static function record(string $objectName, RecordAction $action): string
    {
        return \sprintf('record.%s.%s', $objectName, $action->value);
    }
}
