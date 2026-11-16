<?php

declare(strict_types=1);

namespace App\Shared\Workspace;

/**
 * A row of core.workspace, the workspace registry.
 */
final readonly class Workspace
{
    /**
     * @param string           $id         a UUID
     * @param int              $number     a small integer of its own, for fedale/setting-bundle, whose workspace ids are integers
     * @param non-empty-string $dataSchema the schema of its record tables
     * @param non-empty-string $rbacSchema the schema of its fedale/rbac-bundle tables, until the bundle knows about workspaces
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public int $number,
        public string $dataSchema,
        public string $rbacSchema,
        public bool $active,
        /** Its plan (Plans), null for the default one. */
        public ?string $plan = null,
    ) {
    }
}
