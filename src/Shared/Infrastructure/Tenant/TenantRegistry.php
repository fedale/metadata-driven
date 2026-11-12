<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant;

use App\Shared\Tenant\Tenant;
use Doctrine\DBAL\Connection;

/**
 * The tenants of core.tenant, read with DBAL: before any tenant is selected, by the request's
 * host or the command's --tenant, and by the schema tools.
 */
final class TenantRegistry
{
    private const string COLUMNS = 'id, slug, name, number, data_schema, rbac_schema, status';

    /** What a schema name of the registry may be: it goes into SQL and the search_path unquoted. */
    private const string SCHEMA_NAME = '/^[a-z_][a-z0-9_]{0,62}$/';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function bySlug(string $slug): ?Tenant
    {
        $row = $this->connection->fetchAssociative(\sprintf('SELECT %s FROM core.tenant WHERE slug = ?', self::COLUMNS), [$slug]);

        return false === $row ? null : self::tenant($row);
    }

    public function byId(string $id): ?Tenant
    {
        $row = $this->connection->fetchAssociative(\sprintf('SELECT %s FROM core.tenant WHERE id::text = ?', self::COLUMNS), [$id]);

        return false === $row ? null : self::tenant($row);
    }

    /**
     * @return list<Tenant> by slug
     */
    public function all(): array
    {
        return array_map(
            self::tenant(...),
            $this->connection->fetchAllAssociative(\sprintf('SELECT %s FROM core.tenant ORDER BY slug', self::COLUMNS)),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function tenant(array $row): Tenant
    {
        $id = $row['id'];
        $slug = $row['slug'];
        $name = $row['name'];
        $number = $row['number'];
        $dataSchema = $row['data_schema'];
        $rbacSchema = $row['rbac_schema'];
        if (!\is_string($id) || !\is_string($slug) || !\is_string($name) || !(\is_int($number) || is_numeric($number))
            || !\is_string($dataSchema) || 1 !== preg_match(self::SCHEMA_NAME, $dataSchema)
            || !\is_string($rbacSchema) || 1 !== preg_match(self::SCHEMA_NAME, $rbacSchema)) {
            throw new \UnexpectedValueException(\sprintf('The row of tenant "%s" in core.tenant is malformed.', \is_string($slug) ? $slug : '?'));
        }

        return new Tenant($id, $slug, $name, (int) $number, $dataSchema, $rbacSchema, 'active' === $row['status']);
    }
}
