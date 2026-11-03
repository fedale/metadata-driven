<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\File;

use App\Module\File\Contract\Dto\ThumbnailSize;
use App\Module\File\Contract\Exception\FileNotFound;
use App\Module\File\Contract\FileStorageInterface;
use App\Module\File\Contract\ThumbnailerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ThumbnailerTest extends KernelTestCase
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

    public function testFitsTheImageInTheBoxWithoutGrowingIt(): void
    {
        $wide = $this->store($this->png(1200, 300));
        $tiny = $this->store($this->png(20, 10));
        $thumbnails = self::getContainer()->get(ThumbnailerInterface::class);

        self::assertSame([64, 16], $this->dimensions($thumbnails->thumbnail($wide, ThumbnailSize::Small)?->bytes));
        self::assertSame([240, 60], $this->dimensions($thumbnails->thumbnail($wide, ThumbnailSize::Medium)?->bytes));
        self::assertSame([20, 10], $this->dimensions($thumbnails->thumbnail($tiny, ThumbnailSize::Medium)?->bytes));
    }

    public function testGivesNoThumbnailOfWhatItCannotScale(): void
    {
        $thumbnails = self::getContainer()->get(ThumbnailerInterface::class);
        $png = (string) file_get_contents($this->png(40, 30));

        // Not an image.
        self::assertNull($thumbnails->thumbnail($this->store($this->file("%PDF-1.4\n")), ThumbnailSize::Small));
        // The header of an image, cut short: its size is known, its pixels cannot be decoded.
        self::assertNull($thumbnails->thumbnail($this->store($this->file(substr($png, 0, 40))), ThumbnailSize::Small));
        // A header claiming 20,000 x 20,000 pixels: never decoded.
        $huge = substr_replace(substr($png, 0, 40), pack('NN', 20_000, 20_000), 16, 8);
        self::assertNull($thumbnails->thumbnail($this->store($this->file($huge)), ThumbnailSize::Small));
    }

    public function testAnUnknownFileIsNotFound(): void
    {
        $this->expectException(FileNotFound::class);

        self::getContainer()->get(ThumbnailerInterface::class)->thumbnail('0193a5b2-0000-7000-8000-000000000000', ThumbnailSize::Small);
    }

    private function store(string $path): string
    {
        return self::getContainer()->get(FileStorageInterface::class)->store($path, 'image.png')->id;
    }

    /**
     * @return array{int, int}|null
     */
    private function dimensions(?string $bytes): ?array
    {
        $size = null !== $bytes ? getimagesizefromstring($bytes) : false;

        return false !== $size ? [$size[0], $size[1]] : null;
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
        ob_start();
        imagepng(imagecreatetruecolor($width, $height));

        return $this->file((string) ob_get_clean());
    }
}
