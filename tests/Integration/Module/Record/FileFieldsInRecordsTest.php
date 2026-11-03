<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\FieldType\Contract\Dto\FileUpload;
use App\Module\File\Contract\FileStorageInterface;
use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The "file" field type through the record pipeline, against the real database and the
 * in-memory file storage.
 */
final class FileFieldsInRecordsTest extends KernelTestCase
{
    private const string PDF = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj << /Type /Catalog >> endobj\n";

    private RecordManagerInterface $records;
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        $container = self::getContainer();
        ($container->get(CreateObjectHandler::class))(new CreateObjectCommand('product', 'Product', 'Products'));
        $createField = $container->get(CreateFieldHandler::class);
        $createField(new CreateFieldCommand('product', 'name', 'text', 'Name', isRequired: true));
        $createField(new CreateFieldCommand('product', 'manual', 'file', 'Manual', settings: ['accept' => 'application/pdf', 'maxSize' => 1]));
        $createField(new CreateFieldCommand('product', 'photo', 'file', 'Photo', settings: ['image' => true]));
        $this->records = $container->get(RecordManagerInterface::class);
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

    public function testAFileIsStoredWithTheRecord(): void
    {
        $product = $this->records->create(new RecordInput('product', [
            'name' => 'Oslo chair',
            'manual' => new FileUpload($this->file(self::PDF), 'oslo-manual.pdf'),
            'photo' => new FileUpload($this->png(), 'oslo.png'),
        ]));

        $files = self::getContainer()->get(FileStorageInterface::class);
        $manual = $files->find($this->fileId($product->values['manual'] ?? null));
        self::assertSame('oslo-manual.pdf', $manual?->originalName);
        self::assertSame('application/pdf', $manual->mimeType);
        $photo = $files->find($this->fileId($product->values['photo'] ?? null));
        self::assertSame([4, 3], [$photo?->width, $photo?->height]);
    }

    public function testAnUpdateKeepsReplacesOrRemovesTheFile(): void
    {
        $product = $this->records->create(new RecordInput('product', ['name' => 'Oslo chair', 'manual' => new FileUpload($this->file(self::PDF), 'v1.pdf')]));
        $first = $this->fileId($product->values['manual'] ?? null);

        // Left out, or given as the id it already holds, the file stays.
        self::assertSame($first, $this->records->update($product->id, new RecordInput('product', ['name' => 'Oslo armchair']))->values['manual']);
        self::assertSame($first, $this->records->update($product->id, new RecordInput('product', ['manual' => $first]))->values['manual']);

        $replaced = $this->records->update($product->id, new RecordInput('product', ['manual' => new FileUpload($this->file(self::PDF.'v2'), 'v2.pdf')]));
        $second = $this->fileId($replaced->values['manual'] ?? null);
        self::assertNotSame($first, $second);
        self::assertSame('v2.pdf', self::getContainer()->get(FileStorageInterface::class)->find($second)?->originalName);

        self::assertNull($this->records->update($product->id, new RecordInput('product', ['manual' => null]))->values['manual']);
    }

    public function testTheContentOfAFileDecidesItsType(): void
    {
        $this->assertRefused(
            ['manual' => new FileUpload($this->file('Plain text, not a PDF'), 'fake.pdf')],
            'manual',
            'The mime type of the file is invalid ("text/plain"). Allowed mime types are "application/pdf".',
        );
        $this->assertRefused(['photo' => new FileUpload($this->file(self::PDF), 'photo.png')], 'photo', 'This file is not a valid image.');
    }

    public function testAFileOverTheMaximumSizeIsRefused(): void
    {
        // 1 MB is 1,000,000 bytes.
        $this->assertRefused(
            ['manual' => new FileUpload($this->file(self::PDF.str_repeat('0', 1_000_000)), 'big.pdf')],
            'manual',
            'The file is too large',
        );
    }

    public function testOnlyAStoredFileCanBeReferencedById(): void
    {
        $this->assertRefused(['manual' => '0193a5b2-0000-7000-8000-000000000000'], 'manual', 'This file does not exist.');
        $this->assertRefused(['manual' => 42], 'manual', 'This value should be a file.');
    }

    public function testTheColumnPointsAtTheStoredFile(): void
    {
        // The foreign key compares equal to the one in the database: nothing left to change.
        $plan = self::getContainer()->get(SchemaSynchronizerInterface::class)->plan('product', allowDestructive: true);
        self::assertTrue($plan->isEmpty(), implode("\n", $plan->statements));

        $product = $this->records->create(new RecordInput('product', ['name' => 'Oslo chair', 'manual' => new FileUpload($this->file(self::PDF), 'oslo.pdf')]));
        $this->expectException(ForeignKeyConstraintViolationException::class);
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM core.file WHERE id = ?', [$product->values['manual']]);
    }

    public function testAFileFieldCannotBeUnique(): void
    {
        $this->expectException(MetadataException::class);
        $this->expectExceptionMessage('Field "leaflet" is of type "file", which cannot be unique.');

        (self::getContainer()->get(CreateFieldHandler::class))(new CreateFieldCommand('product', 'leaflet', 'file', 'Leaflet', isUnique: true));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function assertRefused(array $values, string $field, string $message): void
    {
        try {
            $this->records->create(new RecordInput('product', ['name' => 'Oslo chair'] + $values));
            self::fail(\sprintf('The value of "%s" must be refused.', $field));
        } catch (RecordValidationFailed $e) {
            self::assertStringContainsString($message, implode(' ', $e->errors[$field] ?? []));
        }
    }

    private function fileId(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'md-file-');
        file_put_contents($path, $content);

        return $this->paths[] = $path;
    }

    private function png(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(4, 3));

        return $this->file((string) ob_get_clean());
    }
}
