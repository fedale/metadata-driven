<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant;

use App\Shared\Database\TenantScope;
use App\Shared\Tenant\CurrentTenant;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The current tenant as the database session knows it: app.tenant_id, the setting the column
 * defaults and the tenant filter read. Taking it from there rather than keeping an id of its
 * own means PHP and SQL can't disagree on the tenant.
 *
 * Read once per request (one row of core.tenant), and again after reset(): a worker serves
 * the next request, possibly for another tenant, with a fresh read.
 */
final class ConnectionCurrentTenant implements CurrentTenant, ResetInterface
{
    /** @var array{id: string, dataSchema: non-empty-string}|null */
    private ?array $tenant = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function id(): string
    {
        return $this->load()['id'];
    }

    public function dataSchema(): string
    {
        return $this->load()['dataSchema'];
    }

    public function reset(): void
    {
        $this->tenant = null;
    }

    /**
     * @return array{id: string, dataSchema: non-empty-string}
     */
    private function load(): array
    {
        if (null === $this->tenant) {
            $row = $this->connection->fetchAssociative(
                \sprintf('SELECT id, data_schema FROM core.tenant WHERE id = %s', TenantScope::CURRENT),
            );
            $id = false === $row ? null : $row['id'];
            $dataSchema = false === $row ? null : $row['data_schema'];
            if (!\is_string($id) || !\is_string($dataSchema) || '' === $dataSchema) {
                throw new \LogicException('No tenant is selected: the database session has no app.tenant_id naming a row of core.tenant.');
            }
            $this->tenant = ['id' => $id, 'dataSchema' => $dataSchema];
        }

        return $this->tenant;
    }
}
