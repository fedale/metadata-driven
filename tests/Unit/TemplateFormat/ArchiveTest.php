<?php

declare(strict_types=1);

namespace App\Tests\Unit\TemplateFormat;

use App\TemplateFormat\Archive;
use App\TemplateFormat\InvalidPackage;
use App\Tests\Support\RemovesDirectories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArchiveTest extends TestCase
{
    use RemovesDirectories;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/md-archive-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
    }

    public function testPacksATemplateAndUnpacksItTheSame(): void
    {
        $archive = $this->directory.'/shop.tar.gz';
        (new Archive())->pack(PackageCheckerTest::FIXTURE, $archive);
        (new Archive())->unpack($archive, $this->directory.'/out', 10_000_000);

        $files = static function (string $root): array {
            $found = [];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo) {
                    $found[substr($file->getPathname(), \strlen($root) + 1)] = hash_file('sha256', $file->getPathname());
                }
            }
            ksort($found);

            return $found;
        };
        self::assertSame($files(PackageCheckerTest::FIXTURE), $files($this->directory.'/out'));
    }

    public function testTheSamePackageGivesTheSameArchive(): void
    {
        (new Archive())->pack(PackageCheckerTest::FIXTURE, $this->directory.'/a.tar.gz');
        touch(PackageCheckerTest::FIXTURE.'/template.yaml');
        (new Archive())->pack(PackageCheckerTest::FIXTURE, $this->directory.'/b.tar.gz');

        self::assertSame(hash_file('sha256', $this->directory.'/a.tar.gz'), hash_file('sha256', $this->directory.'/b.tar.gz'));
    }

    #[DataProvider('maliciousArchives')]
    public function testRefusesWhatWouldEscapeOrHarm(string $tar, string $expected): void
    {
        file_put_contents($this->directory.'/bad.tar.gz', gzencode($tar));

        try {
            (new Archive())->unpack($this->directory.'/bad.tar.gz', $this->directory.'/out', 1_000_000);
            self::fail('The archive must be refused.');
        } catch (InvalidPackage $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
        self::assertFileDoesNotExist($this->directory.'/escaped');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function maliciousArchives(): iterable
    {
        $end = str_repeat("\0", 1024);
        yield 'a path leaving the target' => [self::entry('../escaped', 'x').$end, 'would leave the template'];
        yield 'an absolute path' => [self::entry('/tmp/escaped', 'x').$end, 'would leave the template'];
        yield 'a symbolic link' => [self::entry('objects/link', '', '2').$end, 'something other than files and directories'];
        yield 'too large once uncompressed' => [self::entry('big.csv', str_repeat('a', 1_000_001)).$end, 'more than 1 MB'];
        yield 'a corrupt header' => [substr_replace(self::entry('template.yaml', 'x'), 'X', 10, 1).$end, 'A header is corrupt'];
        yield 'cut short' => [substr(self::entry('template.yaml', str_repeat('a', 2000)), 0, 1024), 'It ends too soon'];
    }

    /**
     * One tar entry, as any tar tool could write it.
     */
    private static function entry(string $name, string $content, string $type = '0'): string
    {
        $header = str_pad($name, 100, "\0").str_pad('0000644', 8, "\0").str_pad('0000000', 8, "\0").str_pad('0000000', 8, "\0")
            .str_pad(\sprintf('%011o', \strlen($content)), 12, "\0").str_pad('00000000000', 12, "\0").str_repeat(' ', 8)
            .$type.str_repeat("\0", 100)."ustar\x0000".str_repeat("\0", 80).str_repeat("\0", 155).str_repeat("\0", 12);
        $header = substr_replace($header, \sprintf('%06o', array_sum(array_map(\ord(...), str_split($header))))."\0 ", 148, 8);

        return $header.$content.str_repeat("\0", (512 - \strlen($content) % 512) % 512);
    }
}
