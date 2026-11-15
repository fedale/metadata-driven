<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Module\File\Application\FileGarbageCollectorInterface;
use App\Module\File\Contract\Dto\StoredFile;
use App\Module\File\Contract\FileStorageInterface;
use App\Module\File\Infrastructure\StorageKeys;
use App\Shared\Infrastructure\Workspace\WorkspaceRegistry;
use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\Workspace;
use App\Tests\Support\CreatesWorkspaces;
use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Phase 5 of the multi-tenancy plan: each workspace's files in a directory of its own.
 */
final class WorkspaceFilesTest extends KernelTestCase
{
    use CreatesWorkspaces;

    private Connection $connection;
    private FilesystemOperator $storage;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $storage = self::getContainer()->get('record_files.storage');
        self::assertInstanceOf(FilesystemOperator::class, $storage);
        $this->storage = $storage;
    }

    public function testTheSameBytesInTwoWorkspacesAreTwoContents(): void
    {
        $container = self::getContainer();
        $default = $this->workspace('default');
        $ours = $this->store('Oslo chair, assembly');
        $globex = self::createWorkspace($container, 'globex', 'Globex');
        self::useWorkspace($container, $globex);
        $theirs = $this->store('Oslo chair, assembly');

        self::assertSame($ours->sha256, $theirs->sha256);
        self::assertTrue($this->storage->fileExists(StorageKeys::content($default->id, $ours->sha256)));
        self::assertTrue($this->storage->fileExists(StorageKeys::content($globex->id, $theirs->sha256)));
    }

    public function testAWorkspacesGarbageCollectionLeavesTheOthersFilesAlone(): void
    {
        $container = self::getContainer();
        $default = $this->workspace('default');
        $globex = self::createWorkspace($container, 'globex', 'Globex');
        self::useWorkspace($container, $globex);
        $theirs = $this->store('Oslo chair, assembly');
        self::useWorkspace($container, $default);
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
     * Under a role that is neither superuser nor BYPASSRLS, as in TwoWorkspacesTest.
     */
    public function testRowLevelSecurityKeepsAWorkspaceToItsFiles(): void
    {
        $container = self::getContainer();
        $default = $this->workspace('default');
        $ours = $this->store('Oslo chair, assembly');
        $globex = self::createWorkspace($container, 'globex', 'Globex');
        self::useWorkspace($container, $globex);
        $this->store('Globex quarterly report');
        self::useWorkspace($container, $default);

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('CREATE ROLE workspace_probe NOLOGIN');
            $this->connection->executeStatement('GRANT USAGE ON SCHEMA core TO workspace_probe');
            $this->connection->executeStatement('GRANT SELECT ON core.file TO workspace_probe');
            $this->connection->executeStatement('SET ROLE workspace_probe');

            self::assertSame([$ours->id], $this->connection->fetchFirstColumn('SELECT id::text FROM core.file'));
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * Files stored before workspaces had directories: their key at the root of the storage, which
     * workspace:files:move moves, once, into their workspace's directory.
     */
    public function testTheFilesOfBeforeWorkspaceDirectoriesAreMovedIntoThem(): void
    {
        $container = self::getContainer();
        $default = $this->workspace('default');
        $ours = $this->storeAtTheRoot('Oslo chair, assembly');
        $globex = self::createWorkspace($container, 'globex', 'Globex');
        self::useWorkspace($container, $globex);
        $theirs = $this->storeAtTheRoot('Oslo chair, assembly');   // the same root key as ours
        self::useWorkspace($container, $default);
        $orphan = hash('sha256', 'Rolled back');
        $this->storage->write(substr($orphan, 0, 2).'/'.$orphan, 'Rolled back');
        $this->storage->write('thumbnails/small/'.$ours->sha256.'.png', 'a thumbnail');

        $application = new Application(self::$kernel ?? throw new \LogicException('No kernel.'));
        $application->setAutoExit(false);
        $console = new ApplicationTester($application);
        self::assertSame(0, $console->run(['command' => 'workspace:files:move'], ['interactive' => false]), $console->getDisplay());
        self::assertStringContainsString('Moved 2 files to their workspace\'s directory, deleted 2 contents from the root and its thumbnails', $console->getDisplay());

        foreach ([[$default, $ours], [$globex, $theirs]] as [$workspace, $file]) {
            $key = StorageKeys::content($workspace->id, $file->sha256);
            self::assertSame($key, $this->connection->fetchOne('SELECT storage_key FROM core.file WHERE id = ?', [$file->id]));
            self::assertSame('Oslo chair, assembly', $this->storage->read($key));
        }
        self::assertFalse($this->storage->fileExists(substr($ours->sha256, 0, 2).'/'.$ours->sha256));
        self::assertFalse($this->storage->fileExists(substr($orphan, 0, 2).'/'.$orphan));
        self::assertFalse($this->storage->directoryExists('thumbnails'));

        $console->run(['command' => 'workspace:files:move'], ['interactive' => false]);
        self::assertStringContainsString('Moved 0 files', $console->getDisplay(), 'Running it again does nothing.');
    }

    private function store(string $content): StoredFile
    {
        $path = tempnam(sys_get_temp_dir(), 'workspace');
        self::assertIsString($path);
        try {
            file_put_contents($path, $content);

            return self::getContainer()->get(FileStorageInterface::class)->store($path, 'assembly.pdf');
        } finally {
            unlink($path);
        }
    }

    /**
     * A file as stored before workspaces had directories: its bytes and its key at the root.
     */
    private function storeAtTheRoot(string $content): StoredFile
    {
        $file = $this->store($content);
        $root = substr($file->sha256, 0, 2).'/'.$file->sha256;
        $this->storage->write($root, $content);
        $this->storage->delete(StorageKeys::content(self::getContainer()->get(CurrentWorkspace::class)->id(), $file->sha256));
        $this->connection->executeStatement('UPDATE core.file SET storage_key = ? WHERE id = ?', [$root, $file->id]);

        return $file;
    }

    private function workspace(string $slug): Workspace
    {
        $workspace = self::getContainer()->get(WorkspaceRegistry::class)->bySlug($slug);
        self::assertInstanceOf(Workspace::class, $workspace);

        return $workspace;
    }
}
