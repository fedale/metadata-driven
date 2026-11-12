<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Module\File\Application\FileGarbageCollectorInterface;
use App\Module\File\Contract\Dto\StoredFile;
use App\Module\File\Contract\FileStorageInterface;
use App\Module\File\Infrastructure\StorageKeys;
use App\Shared\Infrastructure\Tenant\TenantRegistry;
use App\Shared\Tenant\CurrentTenant;
use App\Shared\Tenant\Tenant;
use App\Tests\Support\CreatesTenants;
use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Phase 5 of the multi-tenancy plan: each tenant's files in a directory of its own.
 */
final class TenantFilesTest extends KernelTestCase
{
    use CreatesTenants;

    private Connection $connection;
    private FilesystemOperator $storage;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $storage = self::getContainer()->get('record_files.storage');
        self::assertInstanceOf(FilesystemOperator::class, $storage);
        $this->storage = $storage;
    }

    public function testTheSameBytesInTwoTenantsAreTwoContents(): void
    {
        $container = self::getContainer();
        $default = $this->tenant('default');
        $ours = $this->store('Oslo chair, assembly');
        $globex = self::createTenant($container, 'globex', 'Globex');
        self::useTenant($container, $globex);
        $theirs = $this->store('Oslo chair, assembly');

        self::assertSame($ours->sha256, $theirs->sha256);
        self::assertTrue($this->storage->fileExists(StorageKeys::content($default->id, $ours->sha256)));
        self::assertTrue($this->storage->fileExists(StorageKeys::content($globex->id, $theirs->sha256)));
    }

    public function testATenantsGarbageCollectionLeavesTheOthersFilesAlone(): void
    {
        $container = self::getContainer();
        $default = $this->tenant('default');
        $globex = self::createTenant($container, 'globex', 'Globex');
        self::useTenant($container, $globex);
        $theirs = $this->store('Oslo chair, assembly');
        self::useTenant($container, $default);
        $ours = $this->store('Oslo chair, assembly');
        // Both referenced by nothing, both old enough to go.
        $this->connection->executeStatement("UPDATE core.file SET created_at = created_at - INTERVAL '2 days'");

        $report = $container->get(FileGarbageCollectorInterface::class)->collect(new \DateTimeImmutable('-1 day'));

        self::assertSame([$ours->id], array_map(static fn ($file): string => $file->id, $report->files));
        self::assertSame(1, $report->contents);
        self::assertFalse($this->storage->fileExists(StorageKeys::content($default->id, $ours->sha256)));
        self::assertTrue($this->storage->fileExists(StorageKeys::content($globex->id, $theirs->sha256)));
        self::assertNotFalse($this->connection->fetchOne('SELECT 1 FROM core.file WHERE id = ?', [$theirs->id]));
    }

    /**
     * Under a role that is neither superuser nor BYPASSRLS, as in TwoTenantsTest.
     */
    public function testRowLevelSecurityKeepsATenantToItsFiles(): void
    {
        $container = self::getContainer();
        $default = $this->tenant('default');
        $ours = $this->store('Oslo chair, assembly');
        $globex = self::createTenant($container, 'globex', 'Globex');
        self::useTenant($container, $globex);
        $this->store('Globex quarterly report');
        self::useTenant($container, $default);

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('CREATE ROLE tenant_probe NOLOGIN');
            $this->connection->executeStatement('GRANT USAGE ON SCHEMA core TO tenant_probe');
            $this->connection->executeStatement('GRANT SELECT ON core.file TO tenant_probe');
            $this->connection->executeStatement('SET ROLE tenant_probe');

            self::assertSame([$ours->id], $this->connection->fetchFirstColumn('SELECT id::text FROM core.file'));
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * Files stored before tenants had directories: their key at the root of the storage, which
     * tenant:files:move moves, once, into their tenant's directory.
     */
    public function testTheFilesOfBeforeTenantDirectoriesAreMovedIntoThem(): void
    {
        $container = self::getContainer();
        $default = $this->tenant('default');
        $ours = $this->storeAtTheRoot('Oslo chair, assembly');
        $globex = self::createTenant($container, 'globex', 'Globex');
        self::useTenant($container, $globex);
        $theirs = $this->storeAtTheRoot('Oslo chair, assembly');   // the same root key as ours
        self::useTenant($container, $default);
        $orphan = hash('sha256', 'Rolled back');
        $this->storage->write(substr($orphan, 0, 2).'/'.$orphan, 'Rolled back');
        $this->storage->write('thumbnails/small/'.$ours->sha256.'.png', 'a thumbnail');

        $application = new Application(self::$kernel ?? throw new \LogicException('No kernel.'));
        $application->setAutoExit(false);
        $console = new ApplicationTester($application);
        self::assertSame(0, $console->run(['command' => 'tenant:files:move'], ['interactive' => false]), $console->getDisplay());
        self::assertStringContainsString('Moved 2 files to their tenant\'s directory, deleted 2 contents from the root and its thumbnails', $console->getDisplay());

        foreach ([[$default, $ours], [$globex, $theirs]] as [$tenant, $file]) {
            $key = StorageKeys::content($tenant->id, $file->sha256);
            self::assertSame($key, $this->connection->fetchOne('SELECT storage_key FROM core.file WHERE id = ?', [$file->id]));
            self::assertSame('Oslo chair, assembly', $this->storage->read($key));
        }
        self::assertFalse($this->storage->fileExists(substr($ours->sha256, 0, 2).'/'.$ours->sha256));
        self::assertFalse($this->storage->fileExists(substr($orphan, 0, 2).'/'.$orphan));
        self::assertFalse($this->storage->directoryExists('thumbnails'));

        $console->run(['command' => 'tenant:files:move'], ['interactive' => false]);
        self::assertStringContainsString('Moved 0 files', $console->getDisplay(), 'Running it again does nothing.');
    }

    private function store(string $content): StoredFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tenant');
        self::assertIsString($path);
        try {
            file_put_contents($path, $content);

            return self::getContainer()->get(FileStorageInterface::class)->store($path, 'assembly.pdf');
        } finally {
            unlink($path);
        }
    }

    /**
     * A file as stored before tenants had directories: its bytes and its key at the root.
     */
    private function storeAtTheRoot(string $content): StoredFile
    {
        $file = $this->store($content);
        $root = substr($file->sha256, 0, 2).'/'.$file->sha256;
        $this->storage->write($root, $content);
        $this->storage->delete(StorageKeys::content(self::getContainer()->get(CurrentTenant::class)->id(), $file->sha256));
        $this->connection->executeStatement('UPDATE core.file SET storage_key = ? WHERE id = ?', [$root, $file->id]);

        return $file;
    }

    private function tenant(string $slug): Tenant
    {
        $tenant = self::getContainer()->get(TenantRegistry::class)->bySlug($slug);
        self::assertInstanceOf(Tenant::class, $tenant);

        return $tenant;
    }
}
