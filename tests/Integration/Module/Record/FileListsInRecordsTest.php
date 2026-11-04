<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\FieldType\Contract\Dto\FileUpload;
use App\Module\File\Contract\FileStorageInterface;
use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Record\Application\RecordManager;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Validation\Application\MetadataRecordValidator;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A file field holding several files ("multiple": true) through the record pipeline: the
 * list is written to its link table, in order, against the real database and the in-memory
 * file storage.
 */
final class FileListsInRecordsTest extends KernelTestCase
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
        $createField(new CreateFieldCommand('product', 'manuals', 'file', 'Manuals', settings: ['accept' => 'application/pdf', 'multiple' => true, 'max' => 3]));
        $createField(new CreateFieldCommand('product', 'gallery', 'file', 'Gallery', isRequired: true, settings: ['image' => true, 'multiple' => true]));
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

    public function testTheFilesAreStoredInTheirOrder(): void
    {
        $product = $this->create([
            'manuals' => [$this->pdf('assembly.pdf'), $this->pdf('care.pdf', 'v2')],
        ]);

        self::assertSame(['assembly.pdf', 'care.pdf'], $this->names($product, 'manuals'));
        self::assertSame(['front.png'], $this->names($product, 'gallery'));
        [$assembly, $care] = $this->ids($product, 'manuals');
        self::assertSame(
            [[0, $assembly], [1, $care]],
            $this->connection()->fetchAllNumeric('SELECT position, target_id FROM data.l_product__manuals WHERE record_id = ? ORDER BY position', [$product->id]),
        );
    }

    public function testAnUpdateKeepsMovesRemovesAndAddsFiles(): void
    {
        $product = $this->create(['manuals' => [$this->pdf('assembly.pdf'), $this->pdf('care.pdf', 'v2'), $this->pdf('warranty.pdf', 'v3')]]);
        [$assembly, $care, $warranty] = $this->ids($product, 'manuals');

        // Left out, the list stays as it is.
        self::assertSame([$assembly, $care, $warranty], $this->ids($this->update($product, ['name' => 'Oslo armchair']), 'manuals'));

        // The care guide moves first, the assembly guide goes, a new file comes at the end.
        $updated = $this->update($product, ['manuals' => [$care, $warranty, $this->pdf('spare-parts.pdf', 'v4')]]);
        self::assertSame(['care.pdf', 'warranty.pdf', 'spare-parts.pdf'], $this->names($updated, 'manuals'));
        self::assertSame([$care, $warranty], \array_slice($this->ids($updated, 'manuals'), 0, 2));
        // Unlinked only: the file stays for the GC.
        self::assertNotNull(self::getContainer()->get(FileStorageInterface::class)->find($assembly));

        self::assertSame([], $this->update($product, ['manuals' => []])->values['manuals']);
        self::assertSame([], $this->update($product, ['manuals' => null])->values['manuals']);
    }

    public function testAFileOfAnotherRecordIsRefused(): void
    {
        $other = $this->create(['manuals' => [$this->pdf('other.pdf')]]);
        $foreign = $this->ids($other, 'manuals')[0];
        $product = $this->create(['name' => 'Bergen table']);

        $this->assertRefused(fn () => $this->update($product, ['manuals' => [$foreign]]), 'manuals', RecordManager::FOREIGN_FILE_MESSAGE);
        // A new record holds no file yet.
        $this->assertRefused(fn () => $this->create(['manuals' => [$foreign]]), 'manuals', RecordManager::FOREIGN_FILE_MESSAGE);
        // Nor does a field of the same record attach the files of another one.
        $this->assertRefused(fn () => $this->update($product, ['manuals' => [$this->ids($product, 'gallery')[0]]]), 'manuals', RecordManager::FOREIGN_FILE_MESSAGE);
        self::assertSame([], self::getContainer()->get(RecordFinderInterface::class)->find('product', $product->id)?->values['manuals']);
    }

    public function testARequiredListNeedsOneFile(): void
    {
        $this->assertRefused(fn () => $this->records->create(new RecordInput('product', ['name' => 'Oslo chair'])), 'gallery', MetadataRecordValidator::REQUIRED_MESSAGE);
        $product = $this->create();
        $this->assertRefused(fn () => $this->update($product, ['gallery' => []]), 'gallery', MetadataRecordValidator::REQUIRED_MESSAGE);
    }

    public function testEveryNewFileIsCheckedAndTheCountIsBounded(): void
    {
        $this->assertRefused(
            fn () => $this->create(['manuals' => [$this->pdf('assembly.pdf'), new FileUpload($this->file('Plain text'), 'fake.pdf')]]),
            'manuals',
            'The mime type of the file is invalid ("text/plain").',
        );
        $this->assertRefused(
            fn () => $this->create(['manuals' => [$this->pdf('a.pdf'), $this->pdf('b.pdf', '2'), $this->pdf('c.pdf', '3'), $this->pdf('d.pdf', '4')]]),
            'manuals',
            'Keep at most 3 files.',
        );
        $this->assertRefused(fn () => $this->create(['manuals' => ['not a file']]), 'manuals', 'This value should be a list of files.');
    }

    /**
     * @param array<string, mixed> $values
     */
    private function create(array $values = []): RecordOutput
    {
        return $this->records->create(new RecordInput('product', $values + ['name' => 'Oslo chair', 'gallery' => [new FileUpload($this->png(), 'front.png')]]));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function update(RecordOutput $record, array $values): RecordOutput
    {
        return $this->records->update($record->id, new RecordInput('product', $values));
    }

    private function assertRefused(callable $write, string $field, string $message): void
    {
        try {
            $write();
            self::fail(\sprintf('The value of "%s" must be refused.', $field));
        } catch (RecordValidationFailed $e) {
            self::assertStringContainsString($message, implode(' ', $e->errors[$field] ?? []));
        }
    }

    /**
     * @return list<string>
     */
    private function ids(RecordOutput $record, string $field): array
    {
        $ids = $record->values[$field] ?? null;
        self::assertIsArray($ids);

        return array_values(array_filter($ids, \is_string(...)));
    }

    /**
     * @return list<string>
     */
    private function names(RecordOutput $record, string $field): array
    {
        $files = self::getContainer()->get(FileStorageInterface::class);

        return array_map(static fn (string $id): string => (string) $files->find($id)?->originalName, $this->ids($record, $field));
    }

    private function pdf(string $name, string $suffix = ''): FileUpload
    {
        // A different content each: the same bytes would still be two files, but read better.
        return new FileUpload($this->file(self::PDF.$suffix), $name);
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

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
