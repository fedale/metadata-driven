<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\File;

use App\Module\File\Contract\Exception\FileException;
use App\Module\File\Contract\Exception\FileNotFound;
use App\Module\File\Contract\FileStorageInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FileStorageTest extends KernelTestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testStoresAFileAndReadsItBack(): void
    {
        $path = $this->png(3, 2);
        $storage = self::getContainer()->get(FileStorageInterface::class);

        // The name lies about the type: the content decides.
        $stored = $storage->store($path, 'chair-oslo.txt');

        self::assertSame('chair-oslo.txt', $stored->originalName);
        self::assertSame('image/png', $stored->mimeType);
        self::assertSame(filesize($path), $stored->size);
        self::assertSame(hash_file('sha256', $path), $stored->sha256);
        self::assertSame([3, 2], [$stored->width, $stored->height]);
        self::assertTrue($stored->isImage());
        self::assertEquals($stored, $storage->find($stored->id));

        $stream = $storage->readStream($stored->id);
        self::assertSame(file_get_contents($path), stream_get_contents($stream));
        fclose($stream);
    }

    public function testDetectsADocumentFromItsContent(): void
    {
        $stored = self::getContainer()->get(FileStorageInterface::class)->store($this->file("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"), 'manual');

        self::assertSame('application/pdf', $stored->mimeType);
        self::assertNull($stored->width);
        self::assertFalse($stored->isImage());
    }

    public function testStoresTheSameBytesOnce(): void
    {
        $storage = self::getContainer()->get(FileStorageInterface::class);
        $path = $this->file('Oslo chair, assembly instructions');

        $first = $storage->store($path, 'manual.txt');
        $second = $storage->store($path, 'manual-copy.txt');

        self::assertNotSame($first->id, $second->id);
        self::assertSame('manual-copy.txt', $second->originalName);
        self::assertCount(1, self::getContainer()->get('record_files.storage')->listContents('', true)->filter(static fn ($item) => $item->isFile())->toArray());
    }

    public function testKeepsOnlyTheBaseNameOfTheOriginalName(): void
    {
        $storage = self::getContainer()->get(FileStorageInterface::class);
        $path = $this->file('contract');

        foreach ([
            '../../etc/pass'."\n".'wd' => 'passwd',
            'C:\Users\ada\contract.pdf' => 'contract.pdf',
            "  \t " => 'file',
            '..' => 'file',
            str_repeat('a', 300).'.pdf' => str_repeat('a', 255),
        ] as $name => $expected) {
            self::assertSame($expected, $storage->store($path, (string) $name)->originalName, (string) $name);
        }
    }

    public function testRefusesAFileItCannotRead(): void
    {
        $this->expectException(FileException::class);
        $this->expectExceptionMessage('The file "missing.pdf" cannot be read.');

        self::getContainer()->get(FileStorageInterface::class)->store(sys_get_temp_dir().'/does-not-exist-'.bin2hex(random_bytes(4)), 'missing.pdf');
    }

    public function testUnknownFilesAreNotFound(): void
    {
        $storage = self::getContainer()->get(FileStorageInterface::class);
        self::assertNull($storage->find('not-a-uuid'));
        self::assertNull($storage->find('0193a5b2-0000-7000-8000-000000000000'));

        $this->expectException(FileNotFound::class);
        $storage->readStream('0193a5b2-0000-7000-8000-000000000000');
    }

    public function testFindsSeveralFilesAtOnceSkippingUnknownIds(): void
    {
        $storage = self::getContainer()->get(FileStorageInterface::class);
        $manual = $storage->store($this->file('manual'), 'manual.txt');
        $photo = $storage->store($this->png(2, 2), 'photo.png');

        $found = $storage->findMany([strtoupper($photo->id), $manual->id, 'not-a-uuid', '0193a5b2-0000-7000-8000-000000000000']);

        self::assertEqualsCanonicalizing([$manual->id, $photo->id], array_keys($found));
        self::assertEquals($photo, $found[$photo->id]);
        self::assertSame([], $storage->findMany([]));
    }

    public function testReportsBytesMissingFromTheStorage(): void
    {
        $stored = self::getContainer()->get(FileStorageInterface::class)->store($this->file('gone'), 'gone.txt');
        /** @var FilesystemOperator $bytes */
        $bytes = self::getContainer()->get('record_files.storage');
        $bytes->delete(substr($stored->sha256, 0, 2).'/'.$stored->sha256);

        $this->expectException(FileNotFound::class);
        $this->expectExceptionMessage('is missing from the storage.');
        self::getContainer()->get(FileStorageInterface::class)->readStream($stored->id);
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'md-file-');
        file_put_contents($path, $content);

        return $this->paths[] = $path;
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return $this->file((string) ob_get_clean());
    }
}
