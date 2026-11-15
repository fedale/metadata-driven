<?php

declare(strict_types=1);

namespace App\Module\Access\Contract;

/**
 * The RBAC items the application checks, created in the current workspace: access:sync, and a new
 * workspace's first step.
 */
interface AccessSetupInterface
{
    /**
     * @return list<string> the items created: those missing only
     */
    public function syncAll(): array;

    /**
     * Creates a permission the application checks for something it made (a manual workflow's),
     * unless it exists: its description is kept then.
     */
    public function ensurePermission(string $name, string $description): void;

    /**
     * Removes a permission ensurePermission() made, and so takes it from every role and user.
     */
    public function removePermission(string $name): void;
}
