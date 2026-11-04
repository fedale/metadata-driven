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
            new Widget(WidgetKind::File, ['accept' => 'application/pdf,image/*', 'maxSize' => 5, 'image' => false, 'multiple' => false, 'max' => null]),
            $type->widget(['accept' => 'application/pdf, IMAGE/*', 'maxSize' => 5] + $type->defaultSettings()),
        );
        // An image field without types offers images to the browser's file picker.
        self::assertSame('image/*', $type->widget(['image' => true] + $type->defaultSettings())->options['accept']);
        self::assertFalse($type->supportsUnique());
        self::assertFalse($type->isWriteOnly());
        self::assertFalse($type->isSortable());
        self::assertSame([], $type->validateSettings(['multiple' => true, 'max' => 5]));
        self::assertSame(['multiple' => true, 'max' => 5], array_intersect_key(
            $type->widget(['multiple' => true, 'max' => 5] + $type->defaultSettings())->options,
            ['multiple' => true, 'max' => true],
        ));
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
        yield 'multiple not bool' => [['multiple' => 'yes'], '"multiple" must be a boolean'];
        yield 'max zero' => [['multiple' => true, 'max' => 0], '"max" must be an integer between 1 and 100'];
        yield 'max over the ceiling' => [['multiple' => true, 'max' => 101], '"max" must be an integer between 1 and 100'];
        yield 'max without multiple' => [['max' => 3], '"max" applies to a field holding several files'];
        yield 'unknown setting' => [['multiple' => true, 'position' => 1], 'Unknown setting "position"'];
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

    public function testDenormalizesAListOfUploadsAndIds(): void
    {
        $type = self::type();
        $settings = ['multiple' => true] + $type->defaultSettings();
        $upload = new FileUpload('/tmp/upload', 'photo.png');
        $other = '0193A5B2-0000-7000-8000-000000000002';

        // In order, the ids lowercase and once each; unknown ids are for the record to refuse.
        self::assertSame(
            [self::STORED_ID, $upload, strtolower($other)],
            $type->denormalize([self::STORED_ID, '', $upload, $other, self::STORED_ID], $settings),
        );
        self::assertSame([$upload], $type->denormalize($upload, $settings));
        // An empty list is no value: "required" asks for at least one file.
        self::assertNull($type->denormalize([], $settings));
        self::assertNull($type->denormalize(['', null], $settings));
        self::assertNull($type->denormalize(null, $settings));
    }

    public function testRefusesAListItemThatIsNoFile(): void
    {
        $this->expectException(InvalidFieldValue::class);
        $this->expectExceptionMessage('This value should be a list of files.');

        self::type()->denormalize([self::STORED_ID, 'photo.png'], ['multiple' => true] + self::type()->defaultSettings());
    }

    public function testChecksEveryUploadOfAListAndItsCount(): void
    {
        $type = self::type();
        $settings = ['accept' => 'application/pdf', 'multiple' => true, 'max' => 2] + $type->defaultSettings();
        $validator = Validation::createValidator();
        $pdf = new FileUpload($this->file("%PDF-1.4\n"), 'manual.pdf');

        self::assertCount(0, $validator->validate([self::STORED_ID, $pdf], $type->constraints($settings)));
        $violations = $validator->validate([$pdf, new FileUpload($this->file('Plain text'), 'fake.pdf')], $type->constraints($settings));
        self::assertCount(1, $violations);
        self::assertSame('[1]', $violations->get(0)->getPropertyPath());
        self::assertStringContainsString('Keep at most 2 files.', (string) $validator->validate([self::STORED_ID, $pdf, $pdf], $type->constraints($settings)));
        self::assertCount(0, $validator->validate(null, $type->constraints($settings)));
    }

    public function testStoresTheUploadsOfAListAndKeepsItsOrder(): void
    {
        $type = self::type();
        $settings = ['multiple' => true] + $type->defaultSettings();
        $kept = '0193a5b2-0000-7000-8000-000000000002';

        self::assertSame([$kept, self::STORED_ID], $type->toStorage([$kept, new FileUpload('/tmp/upload', 'photo.png')], $settings));
        self::assertSame([], $type->toStorage(null, $settings));
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

            public function findMany(array $ids): array
            {
                throw new \LogicException('Not listed in these tests.');
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
