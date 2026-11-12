<?php

declare(strict_types=1);

namespace App\Module\Template\Domain\Repository;

use App\Module\Template\Domain\Model\TemplateSource;

/**
 * The indexes a tenant looks into: its own, and those offered to every tenant (no tenant: added
 * by the landlord, tenant:template:repo). A tenant reads both and writes its own only; the
 * landlord's session reads and writes the shared ones.
 */
interface TemplateSourceRepository
{
    /**
     * @return list<TemplateSource> by name
     */
    public function all(): array;

    /**
     * Whether an index is offered to every tenant rather than the current one's.
     */
    public function isShared(TemplateSource $source): bool;

    /**
     * Whether the name is taken where an index under it would be seen: in a tenant, by one of
     * its own or a shared one; as the landlord, by any index of any tenant.
     */
    public function isNameTaken(string $name): bool;

    public function findByName(string $name): ?TemplateSource;

    public function save(TemplateSource $source): void;

    public function remove(TemplateSource $source): void;
}
