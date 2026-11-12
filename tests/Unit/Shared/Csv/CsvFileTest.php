<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Csv;

use App\Shared\Csv\CsvFile;
use App\Tests\Support\RemovesDirectories;
use PHPUnit\Framework\TestCase;

final class CsvFileTest extends TestCase
{
    use RemovesDirectories;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/md-csv-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
    }

    public function testReadsCommasAndSemicolons(): void
    {
        $expected = [['line' => 2, 'values' => ['code' => 'TO', 'name' => 'Torino, città']], ['line' => 3, 'values' => ['code' => 'MI', 'name' => null]]];

        self::assertSame($expected, CsvFile::read($this->file("\xEF\xBB\xBFcode,name\nTO,\"Torino, città\"\nMI,\n")));
        self::assertSame($expected, CsvFile::read($this->file("code;name\r\nTO;Torino, città\r\nMI;\r\n"), CsvFile::SEMICOLON));
    }

    public function testDetectsTheSeparatorOfTheHeader(): void
    {
        self::assertSame(CsvFile::COMMA, CsvFile::detectSeparator($this->file("code,name\nTO,Torino\n")));
        self::assertSame(CsvFile::SEMICOLON, CsvFile::detectSeparator($this->file("\xEF\xBB\xBFcode;name;region\nTO;Torino;01\n")));
        // A comma inside quotes is no separator.
        self::assertSame(CsvFile::SEMICOLON, CsvFile::detectSeparator($this->file("\"code, short\";name\nTO;Torino\n")));
        self::assertSame(CsvFile::COMMA, CsvFile::detectSeparator($this->file('')));
    }

    public function testRefusesAFileThatIsNotUtf8(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Line 2 is not UTF-8');

        // "città" in Windows-1252, as an old Excel saves it.
        CsvFile::read($this->file("code,name\nTO,citt\xE0\n"));
    }

    public function testRefusesARepeatedHeader(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Column 3 repeats the header "name"');

        CsvFile::read($this->file("code,name,name\nTO,Torino,Turin\n"));
    }

    public function testRefusesALineThatDoesNotFitTheHeader(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Line 2 has 1 values, the header 2.');

        // Read with commas, a semicolon file is one column per line.
        CsvFile::read($this->file("code,name\nTO;Torino\n"));
    }

    private function file(string $content): string
    {
        $path = $this->directory.'/'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($path, $content);

        return $path;
    }
}
