<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace;

use App\Shared\Database\WorkspaceScope;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Restricts every ORM read of a workspace's table to the current workspace: DQL, findBy(), find(),
 * and the lazy loads of associations. Enabled for every entity manager (doctrine.yaml).
 *
 * The column is unmapped, so the condition names it in SQL; it compares with the session's
 * setting rather than a parameter, the same expression the insert trigger uses.
 *
 * SELECTs only: Doctrine applies no filter to a DQL UPDATE or DELETE. Write those in SQL with
 * the workspace condition (DoctrineTranslationRepository::write()), or load the entities and
 * remove them one by one.
 */
final class WorkspaceFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!WorkspaceScope::isScoped($targetEntity->getTableName())) {
            return '';
        }

        $condition = \sprintf('%s.%s = %s', $targetTableAlias, WorkspaceScope::COLUMN, WorkspaceScope::CURRENT);

        // The rows offered to every workspace too, read-only for them (WorkspaceScope::SHARED).
        return WorkspaceScope::isShared($targetEntity->getTableName())
            ? \sprintf('(%s OR %s.%s IS NULL)', $condition, $targetTableAlias, WorkspaceScope::COLUMN)
            : $condition;
    }
}
