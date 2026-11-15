<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace;

use App\Shared\Workspace\Workspace;
use App\Shared\Workspace\Workspaces;
use Doctrine\DBAL\Connection;

/**
 * The workspaces of core.workspace, read with DBAL: before any workspace is selected, by the request's
 * host or the command's --workspace, and by the schema tools.
 */
final class WorkspaceRegistry implements Workspaces
{
    private const string COLUMNS = 'id, slug, name, number, data_schema, rbac_schema, status';

    /** What a schema name of the registry may be: it goes into SQL and the search_path unquoted. */
    private const string SCHEMA_NAME = '/^[a-z_][a-z0-9_]{0,62}$/';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function bySlug(string $slug): ?Workspace
    {
        $row = $this->connection->fetchAssociative(\sprintf('SELECT %s FROM core.workspace WHERE slug = ?', self::COLUMNS), [$slug]);

        return false === $row ? null : self::workspace($row);
    }

    public function byId(string $id): ?Workspace
    {
        $row = $this->connection->fetchAssociative(\sprintf('SELECT %s FROM core.workspace WHERE id::text = ?', self::COLUMNS), [$id]);

        return false === $row ? null : self::workspace($row);
    }

    /**
     * @return list<Workspace> by slug
     */
    public function all(): array
    {
        return array_map(
            self::workspace(...),
            $this->connection->fetchAllAssociative(\sprintf('SELECT %s FROM core.workspace ORDER BY slug', self::COLUMNS)),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function workspace(array $row): Workspace
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
            throw new \UnexpectedValueException(\sprintf('The row of workspace "%s" in core.workspace is malformed.', \is_string($slug) ? $slug : '?'));
        }

        return new Workspace($id, $slug, $name, (int) $number, $dataSchema, $rbacSchema, 'active' === $row['status']);
    }
}
