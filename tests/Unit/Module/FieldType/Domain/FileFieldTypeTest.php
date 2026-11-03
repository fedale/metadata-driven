<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\FieldType\Domain;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\FileUpload;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\Exception\InvalidFieldValue;
use App\Module\FieldType\Domain\Type\FileFieldType;
use App\Module\File\Contract\Dto\StoredFile;
use App\Module\File\Contract\FileStorageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class FileFieldTypeTest extends TestCase
{
    // Public: the storage double below reads it.
    public const string STORED_ID = '0193a5b2-0000-7000-8000-000000000001';

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testDescribesItsColumnAndWidget(): void
    {
        $type = self::type();

        self::assertSame([], $type->validateSettings(['accept' => 'application/pdf, IMAGE/*', 'maxSize' => 5]));
        self::assertEquals(new ColumnSpec(ColumnType::Guid, referencesCoreTable: 'file'), $type->columnSpec($type->defaultSettings()));
        self::assertEquals(
            new Widget(WidgetKind::File, ['accept' => 'application/pdf,image/*', 'maxSize' => 5, 'image' => false]),
            $type->widget(['accept' => 'application/pdf, IMAGE/*', 'maxSize' => 5] + $type->defaultSettings()),
        );
        // An image field without types offers images to the browser's file picker.
        self::assertSame('image/*', $type->widget(['image' => true] + $type->defaultSettings())->options['accept']);
        self::assertFalse($type->supportsUnique());
        self::assertFalse($type->isWriteOnly());
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsAreReported(array $settings, string $expectedError): void
    {
        self::assertStringContainsString($expectedError, implode(' ', self::type()->validateSettings($settings)));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'accept not a MIME type' => [['accept' => 'pdf'], '"pdf" is not a MIME type'];
        yield 'accept not a string' => [['accept' => ['application/pdf']], '"accept" must be a string'];
        yield 'maxSize zero' => [['maxSize' => 0], 'between 1 and 100 (MB)'];
        yield 'maxSize over the ceiling' => [['maxSize' => 101], 'between 1 and 100 (MB)'];
        yield 'image not bool' => [['image' => 'yes'], '"image" must be a boolean'];
        yield 'image with a document type' => [['image' => true, 'accept' => 'image/png, application/pdf'], '"application/pdf" is not an image type'];
        yield 'unknown setting' => [['multiple' => true], 'Unknown setting "multiple"'];
    }

    public function testDenormalizesUploadsAndStoredIds(): void
    {
        $type = self::type();
        $upload = new FileUpload('/tmp/upload', 'manual.pdf');

        self::assertSame($upload, $type->denormalize($upload, $type->defaultSettings()));
        self::assertSame(self::STORED_ID, $type->denormalize(self::STORED_ID, $type->defaultSettings()));
        self::assertNull($type->denormalize('', $type->defaultSettings()));
        self::assertNull($type->denormalize(null, $type->defaultSettings()));
    }

    public function testRefusesAnIdOfNoStoredFile(): void
    {
        $this->expectException(InvalidFieldValue::class);
        $this->expectExceptionMessage('This file does not exist.');

        self::type()->denormalize('0193a5b2-0000-7000-8000-000000000099', self::type()->defaultSettings());
    }

    public function testTheRulesApplyToANewFileOnly(): void
    {
        $type = self::type();
        $settings = ['accept' => 'application/pdf'] + $type->defaultSettings();
        $validator = Validation::createValidator();

        $text = new FileUpload($this->file('Plain text'), 'fake.pdf');
        self::assertStringContainsString('mime type of the file is invalid', (string) $validator->validate($text, $type->constraints($settings)));
        self::assertCount(0, $validator->validate(new FileUpload($this->file("%PDF-1.4\n"), 'manual.pdf'), $type->constraints($settings)));
        // A stored file was checked when it was uploaded: its id is not a path.
        self::assertCount(0, $validator->validate(self::STORED_ID, $type->constraints($settings)));
    }

    public function testStoresANewFileAndKeepsAStoredOne(): void
    {
        $type = self::type();

        self::assertSame(self::STORED_ID, $type->toStorage(new FileUpload('/tmp/upload', 'manual.pdf'), $type->defaultSettings()));
        self::assertSame(self::STORED_ID, $type->toStorage(self::STORED_ID, $type->defaultSettings()));
        self::assertNull($type->toStorage(null, $type->defaultSettings()));
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'md-file-');
        file_put_contents($path, $content);

        return $this->paths[] = $path;
    }

    /**
     * A storage that knows one file, and stores every new one under its id.
     */
    private static function type(): FileFieldType
    {
        return new FileFieldType(new class implements FileStorageInterface {
            public function store(string $path, string $originalName): StoredFile
            {
                return self::stored($originalName);
            }

            public function find(string $id): ?StoredFile
            {
                return FileFieldTypeTest::STORED_ID === $id ? self::stored('manual.pdf') : null;
            }

            public function readStream(string $id)
            {
                throw new \LogicException('Not read in these tests.');
            }

            private static function stored(string $name): StoredFile
            {
                return new StoredFile(FileFieldTypeTest::STORED_ID, $name, 'application/pdf', 9, str_repeat('0', 64), null, null, new \DateTimeImmutable());
            }
        });
    }
}
