<?php

declare(strict_types=1);

namespace App\Shared\Tenant;

/**
 * The console of the installation, for its operators (docs/internal/tenant-admin-ui-plan.md,
 * part B): on its own host, <label>.<base host>, in the landlord's session.
 */
final class OperatorConsole
{
    /** Set on a request for the console's host: read it per request, never keep it. */
    public const string REQUEST_ATTRIBUTE = '_operator_console';

    /** What an operator holds on the console, and only there. */
    public const string ROLE = 'ROLE_OPERATOR';

    /** The console's paths: every one on its host is one of these, and none on a tenant's. */
    public const string PATH_PREFIX = '/console';

    /**
     * Slugs no tenant takes: the console's label, and the hosts an installation may want for
     * itself.
     */
    public const array RESERVED_SLUGS = ['console', 'admin', 'www', 'api', 'mail', 'static'];
}
