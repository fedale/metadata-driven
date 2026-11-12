<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\File;

use App\Module\FieldType\Contract\Dto\FileUpload;
use App\Module\File\Application\Dto\CollectedFile;
use App\Module\File\Application\Dto\GarbageReport;
use App\Module\File\Application\FileGarbageCollectorInterface;
use App\Module\File\Contract\Dto\StoredFile;
use App\Module\File\Contract\Dto\ThumbnailSize;
use App\Module\File\Contract\FileStorageInterface;
use App\Module\File\Contract\ThumbnailerInterface;
use App\Module\File\Infrastructure\StorageKeys;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Shared\Tenant\CurrentTenant;
use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * metadata:files:gc against the real database (rolled back with each test) and the in-memory
 * file storage. The files are stored "two days ago" by moving their created_at, so the default
 * grace period of 24 hours lets them go.
 */
final class FileGarbageCollectorTest extends KernelTestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        $manager = self::getContainer()->get(MetadataManagerInterface::class);
        $manager->createObject('product', 'Product', 'Products');
        $manager->createField('product', 'name', 'text', 'Name', required: true);
        $manager->createField('product', 'manual', 'file', 'Manual');
        $manager->createField('product', 'gallery', 'file', 'Gallery', settings: ['multiple' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testDeletesTheFilesNothingReferences(): void
    {
        $chair = $this->create(['manual' => $this->upload('Oslo chair, assembly', 'assembly.pdf'), 'gallery' => [$this->upload('front', 'front.png'), $this->upload('back', 'back.png')]]);
        [$front, $back] = $this->fileIds($chair, 'gallery');
        // A record in the bin can be restored: it keeps its files.
        $binned = $this->create(['manual' => $this->upload('Bergen sofa, assembly', 'sofa.pdf')]);
        self::getContainer()->get(RecordManagerInterface::class)->delete('product', $binned->id);
        // Unlinked from its list: only the GC deletes it.
        $this->records()->update($chair->id, new RecordInput('product', ['gallery' => [$front]]));
        $orphan = $this->store('Never attached', 'draft.pdf');
        // The same bytes as the chair's manual: its row goes, the bytes stay.
        $copy = $this->store('Oslo chair, assembly', 'assembly-copy.pdf');
        $this->ageEveryFile();

        $report = $this->collector()->collect(new \DateTimeImmutable('-24 hours'));

        self::assertEqualsCanonicalizing([$back, $orphan->id, $copy->id], self::ids($report));
        self::assertSame(2, $report->contents);
        self::assertSame(\strlen('back') + \strlen('Never attached'), $report->contentBytes);
        foreach ([$back, $orphan->id, $copy->id] as $gone) {
            self::assertFalse($this->rowExists($gone));
        }
        // The referenced files keep their bytes.
        self::assertSame(['Oslo chair, assembly', 'Bergen sofa, assembly', 'front'], [
            $this->bytes($this->fileIds($chair, 'manual')[0]),
            $this->bytes($this->fileIds($binned, 'manual')[0]),
            $this->bytes($front),
        ]);
        self::assertSame(3, $this->countContents(), 'The bytes of the chair manual, the sofa manual and the front photo.');

        self::assertTrue($this->collector()->collect(new \DateTimeImmutable('-24 hours'))->isEmpty());
    }

    public function testKeepsTheFilesOfArchivedFieldsUntilTheyArePurged(): void
    {
        $chair = $this->create(['manual' => $this->upload('Oslo chair, assembly', 'assembly.pdf'), 'gallery' => [$this->upload('front', 'front.png')]]);
        $this->ageEveryFile();
        $manager = self::getContainer()->get(MetadataManagerInterface::class);
        $manager->archiveField('product', 'manual');
        $manager->archiveField('product', 'gallery');

        self::assertTrue($this->collector()->collect(new \DateTimeImmutable('-24 hours'))->isEmpty());

        $manager->purgeField('product', 'manual');
        $manager->purgeField('product', 'gallery');
        $report = $this->collector()->collect(new \DateTimeImmutable('-24 hours'));

        self::assertEqualsCanonicalizing([...$this->fileIds($chair, 'manual'), ...$this->fileIds($chair, 'gallery')], self::ids($report));
        self::assertSame(2, $report->contents);
        self::assertSame(0, $this->countContents());
    }

    public function testKeepsTheFilesStoredDuringTheGracePeriod(): void
    {
        $old = $this->store('Old draft', 'old.pdf');
        $this->ageEveryFile();
        // Its record not written yet: the row is not referenced, but the upload is recent.
        $recent = $this->store('Upload in progress', 'new.pdf');

        $report = $this->collector()->collect(new \DateTimeImmutable('-24 hours'));

        self::assertSame([$old->id], self::ids($report));
        self::assertTrue($this->rowExists($recent->id));
        self::assertSame('Upload in progress', $this->bytes($recent->id));
    }

    public function testDeletesStrayBytesAndThumbnailsButNothingElse(): void
    {
        $storage = $this->storage();
        // Bytes whose row rolled back with the transaction of their record.
        $stray = hash('sha256', 'Rolled back');
        $storage->write($this->contentKey($stray), 'Rolled back');
        // Not written by the File module: not ours to delete.
        $storage->write('exports/products.csv', 'name');
        $photo = $this->create(['manual' => new FileUpload($this->png(4, 3), 'photo.png')])->values['manual'];
        self::assertIsString($photo);
        $orphanPhoto = $this->files()->store($this->png(6, 5), 'unused.png');
        foreach ([$photo, $orphanPhoto->id] as $id) {
            self::getContainer()->get(ThumbnailerInterface::class)->thumbnail($id, ThumbnailSize::Small);
        }
        $this->ageEveryFile();

        $report = $this->collector()->collect(new \DateTimeImmutable('-24 hours'));

        self::assertSame([$orphanPhoto->id], self::ids($report));
        self::assertSame(2, $report->contents);
        self::assertSame(1, $report->thumbnails);
        self::assertFalse($storage->fileExists($this->contentKey($stray)));
        self::assertTrue($storage->fileExists('exports/products.csv'));
        $thumbnails = $storage->listContents($this->tenant().'/thumbnails', true)->filter(static fn ($item): bool => $item->isFile())->map(static fn ($item): string => $item->path())->toArray();
        self::assertSame([StorageKeys::thumbnail($this->tenant(), 'small', (string) $this->files()->find($photo)?->sha256, 'png')], $thumbnails);
    }

    public function testStoringAndCollectingTakeTheLockOfTheContent(): void
    {
        $stored = $this->store('Oslo chair, assembly', 'assembly.pdf');
        $stray = hash('sha256', 'Rolled back');
        $this->storage()->write($this->contentKey($stray), 'Rolled back');
        self::assertFalse($this->holdsTheLockOf($this->contentKey($stray)));

        $this->collector()->collect(new \DateTimeImmutable('-24 hours'));

        // Held until the outermost transaction ends: here, the one each test is rolled back in.
        self::assertTrue($this->holdsTheLockOf($this->contentKey($stored->sha256)));
        self::assertTrue($this->holdsTheLockOf($this->contentKey($stray)));
    }

    public function testADryRunReportsWithoutDeleting(): void
    {
        $orphan = $this->store('Never attached', 'draft.pdf');
        $this->ageEveryFile();

        $dryRun = $this->collector()->collect(new \DateTimeImmutable('-24 hours'), dryRun: true);

        self::assertSame([$orphan->id], self::ids($dryRun));
        self::assertSame(1, $dryRun->contents);
        self::assertSame('Never attached', $this->bytes($orphan->id));
        self::assertEquals($dryRun, $this->collector()->collect(new \DateTimeImmutable('-24 hours')));
        self::assertFalse($this->rowExists($orphan->id));
    }

    public function testTheCommandTakesAGracePeriod(): void
    {
        // The command runs in a new kernel, with a new in-memory storage: store there.
        $application = new Application(self::bootKernel());
        $orphan = $this->store('Never attached', 'draft.pdf');
        $this->ageEveryFile();
        $command = new CommandTester($application->find('metadata:files:gc'));

        self::assertSame(Command::INVALID, $command->execute(['--older-than' => '2 weeks']));
        self::assertStringContainsString('"2 weeks" is not a duration', $command->getDisplay());

        self::assertSame(Command::SUCCESS, $command->execute(['--older-than' => '3d']));
        self::assertStringContainsString('Nothing to delete', $command->getDisplay());

        self::assertSame(Command::SUCCESS, $command->execute(['--dry-run' => true]));
        $display = (string) preg_replace('/\s+/', ' ', $command->getDisplay());
        self::assertStringContainsString($orphan->id.' draft.pdf 14 B', $display);
        self::assertStringContainsString('Would delete 1 file, the bytes of 1 stored content (14 B)', $display);
        self::assertTrue($this->rowExists($orphan->id));

        self::assertSame(Command::SUCCESS, $command->execute([]));
        self::assertStringContainsString('Deleted 1 file', $command->getDisplay());
        self::assertFalse($this->rowExists($orphan->id));
    }

    /**
     * @return list<string>
     */
    private static function ids(GarbageReport $report): array
    {
        return array_map(static fn (CollectedFile $file): string => $file->id, $report->files);
    }

    /**
     * The files a field of a record holds, one or several.
     *
     * @return list<string>
     */
    private function fileIds(RecordOutput $record, string $field): array
    {
        $ids = array_values(array_filter((array) $record->values[$field], \is_string(...)));
        self::assertNotSame([], $ids);

        return $ids;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function create(array $values): RecordOutput
    {
        return $this->records()->create(new RecordInput('product', $values + ['name' => 'Oslo chair']));
    }

    private function upload(string $content, string $name): FileUpload
    {
        return new FileUpload($this->file($content), $name);
    }

    private function store(string $content, string $name): StoredFile
    {
        return $this->files()->store($this->file($content), $name);
    }

    private function ageEveryFile(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE core.file SET created_at = created_at - INTERVAL '2 days'");
    }

    /**
     * Read in SQL: the entity manager would still know a row the GC deleted with DBAL.
     */
    private function rowExists(string $id): bool
    {
        return false !== self::getContainer()->get(Connection::class)->fetchOne('SELECT 1 FROM core.file WHERE id = ?', [$id]);
    }

    /**
     * A bigint advisory lock is listed with its high half in classid and its low half in objid.
     */
    private function holdsTheLockOf(string $storageKey): bool
    {
        return true === self::getContainer()->get(Connection::class)->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND granted
                AND ((classid::bigint << 32) | objid::bigint) = hashtextextended(?, 0))",
            [$storageKey],
        );
    }

    private function tenant(): string
    {
        return self::getContainer()->get(CurrentTenant::class)->id();
    }

    private function contentKey(string $sha256): string
    {
        return StorageKeys::content($this->tenant(), $sha256);
    }

    private function bytes(string $id): string
    {
        $stream = $this->files()->readStream($id);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }

    private function countContents(): int
    {
        return \count($this->storage()->listContents('', true)->filter(static fn ($item): bool => $item->isFile() && !str_contains($item->path(), '/thumbnails/'))->toArray());
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'md-file-');
        file_put_contents($path, $content);

        return $this->paths[] = $path;
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function png(int $width, int $height): string
    {
        ob_start();
        imagepng(imagecreatetruecolor($width, $height));

        return $this->file((string) ob_get_clean());
    }

    private function collector(): FileGarbageCollectorInterface
    {
        return self::getContainer()->get(FileGarbageCollectorInterface::class);
    }

    private function files(): FileStorageInterface
    {
        return self::getContainer()->get(FileStorageInterface::class);
    }

    private function records(): RecordManagerInterface
    {
        return self::getContainer()->get(RecordManagerInterface::class);
    }

    private function storage(): FilesystemOperator
    {
        $storage = self::getContainer()->get('record_files.storage');
        self::assertInstanceOf(FilesystemOperator::class, $storage);

        return $storage;
    }
}
