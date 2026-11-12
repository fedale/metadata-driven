<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant;

use App\Shared\Database\TenantScope;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Restricts every ORM read of a tenant's table to the current tenant: DQL, findBy(), find(),
 * and the lazy loads of associations. Enabled for every entity manager (doctrine.yaml).
 *
 * The column is unmapped, so the condition names it in SQL; it compares with the session's
 * setting rather than a parameter, the same expression the column's default uses.
 */
final class TenantFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!TenantScope::isScoped($targetEntity->getTableName())) {
            return '';
        }

        return \sprintf('%s.%s = %s', $targetTableAlias, TenantScope::COLUMN, TenantScope::CURRENT);
    }
}
