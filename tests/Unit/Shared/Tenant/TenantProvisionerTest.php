<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Tenant;

use App\Shared\Infrastructure\Tenant\ConnectionCurrentTenant;
use App\Shared\Infrastructure\Tenant\TenantProvisioner;
use App\Shared\Infrastructure\Tenant\TenantRegistry;
use App\Shared\Tenant\Tenant;
use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Deleting a tenant deletes the directory of its files, once its rows are gone for good. The
 * integration tests can't see it: they run in a transaction of their own, which keeps the
 * directory (TenantFilesTest, TenantLifecycleCommandsTest cover the rest).
 */
final class TenantProvisionerTest extends TestCase
{
    private const string ID = '019a7f3c-5b2e-7c41-9d0a-3e6f1b2c4d5e';

    public function testDeletesTheTenantsFilesOnceItsDeletionIsCommitted(): void
    {
        $files = $this->createMock(FilesystemOperator::class);
        $files->expects(self::once())->method('deleteDirectory')->with(self::ID);

        $this->provisioner($this->connection(inTransaction: false), $files)->delete($this->tenant());
    }

    public function testKeepsTheFilesWhileACallersTransactionMayStillRollTheDeletionBack(): void
    {
        $files = $this->createMock(FilesystemOperator::class);
        $files->expects(self::never())->method('deleteDirectory');

        $this->provisioner($this->connection(inTransaction: true), $files)->delete($this->tenant());
    }

    public function testDropsTheSchemasBeforeTheRowWhoseRecordsReferenceTheFiles(): void
    {
        $connection = $this->connection(inTransaction: false);
        $statements = [];
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
            $statements[] = $sql;

            return 1;
        });

        $this->provisioner($connection, $this->createStub(FilesystemOperator::class))->delete($this->tenant());

        self::assertSame([
            'DROP SCHEMA IF EXISTS t_3e6f1b2c4d5e_data CASCADE',
            'DROP SCHEMA IF EXISTS t_3e6f1b2c4d5e_rbac CASCADE',
            'DELETE FROM core.tenant WHERE id = ?',
        ], $statements);
    }

    /**
     * In the landlord's session (no tenant), running what transactional() is given.
     *
     * @return Connection&Stub
     */
    private function connection(bool $inTransaction): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $work): mixed => $work());
        $connection->method('isTransactionActive')->willReturn($inTransaction);

        return $connection;
    }

    private function provisioner(Connection $connection, FilesystemOperator $files): TenantProvisioner
    {
        $registry = new TenantRegistry($connection);

        return new TenantProvisioner($connection, $registry, new ConnectionCurrentTenant($connection, $registry), $files);
    }

    private function tenant(): Tenant
    {
        return new Tenant(self::ID, 'globex', 'Globex', 2, 't_3e6f1b2c4d5e_data', 't_3e6f1b2c4d5e_rbac', true);
    }
}
