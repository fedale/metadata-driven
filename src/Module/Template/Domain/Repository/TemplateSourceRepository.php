<?php

declare(strict_types=1);

namespace App\Module\Template\Domain\Repository;

use App\Module\Template\Domain\Model\TemplateSource;

/**
 * The indexes a workspace looks into: its own, and those offered to every workspace (no workspace: added
 * by the system, workspace:template:repo). A workspace reads both and writes its own only; the
 * system session reads and writes the shared ones.
 */
interface TemplateSourceRepository
{
    /**
     * @return list<TemplateSource> by name
     */
    public function all(): array;

    /**
     * Whether an index is offered to every workspace rather than the current one's.
     */
    public function isShared(TemplateSource $source): bool;

    /**
     * Whether the name is taken where an index under it would be seen: in a workspace, by one of
     * its own or a shared one; as the system, by any index of any workspace.
     */
    public function isNameTaken(string $name): bool;

    public function findByName(string $name): ?TemplateSource;

    public function save(TemplateSource $source): void;

    public function remove(TemplateSource $source): void;
}
