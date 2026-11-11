<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Import;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\RecordAction;
use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * What the import wizard offers for the columns of a file: the fields a column may fill, the
 * field proposed for each header, the unique fields a relation may be matched by; and the rows
 * of the file renamed after their fields, as RecordImporter::importRows() takes them.
 */
final class ImportColumns
{
    public function __construct(
        // The labels in the user's language: they are shown, and matched against the headers.
        #[Target('localizedMetadata')]
        private readonly MetadataRegistryInterface $metadata,
        private readonly AccessCheckerInterface $access,
    ) {
    }

    /**
     * The fields a column may fill, by the same permissions as the record form:
     *
     * - no file field: a CSV file uploaded alone brings no files (the console imports them);
     * - no many-to-many field going through a junction object: its links are junction records,
     *   written with their own permissions and cascades (the form's rows);
     * - a relation or many-to-many field only when the user may read the related records, as
     *   picking one in the form needs (its lookup): matching cells against them otherwise
     *   tells which values exist.
     *
     * @return array<string, FieldDefinitionDto> by name, in the order of the object
     */
    public function fields(ObjectDefinitionDto $object): array
    {
        return array_filter($object->fields, function (FieldDefinitionDto $field): bool {
            if ('file' === $field->type || true === $field->manyToMany()?->goesThrough()) {
                return false;
            }
            $target = $field->target();

            return null === $target || $this->access->canRecords($target, RecordAction::Read);
        });
    }

    /**
     * The field each header names, by machine name or by label, case, accents, spaces and
     * underscores aside; null when none does.
     *
     * @param list<string> $headers
     *
     * @return array<string, string|null> header => field
     */
    public function propose(ObjectDefinitionDto $object, array $headers): array
    {
        $byName = [];
        foreach ($this->fields($object) as $name => $field) {
            $byName[self::normalize($field->label)] ??= $name;
            $byName[self::normalize($name)] = $name;
        }
        $mapping = [];
        $taken = [];
        foreach ($headers as $header) {
            $field = $byName[self::normalize($header)] ?? null;
            // A field fills one column: the first one naming it.
            $mapping[$header] = null === $field || isset($taken[$field]) ? null : $field;
            if (null !== $field) {
                $taken[$field] = true;
            }
        }

        return $mapping;
    }

    /**
     * The unique fields of the target of a relation or many-to-many field: what its cells may
     * hold instead of record ids.
     *
     * @return array<string, FieldDefinitionDto> by name
     */
    public function matchableFields(FieldDefinitionDto $field): array
    {
        $target = $field->target();

        return null === $target ? [] : array_filter(
            $this->metadata->getObject($target)->fields,
            static fn (FieldDefinitionDto $candidate): bool => $candidate->isUnique,
        );
    }

    /**
     * The rows of the file with the mapped columns only, named after their fields.
     *
     * @param list<array{line: int, values: array<string, string|null>}> $rows
     * @param array<string, string|null>                                 $mapping header => field
     *
     * @return list<array{line: int, values: array<string, string|null>}>
     */
    public static function rename(array $rows, array $mapping): array
    {
        return array_map(static function (array $row) use ($mapping): array {
            $values = [];
            foreach ($mapping as $header => $field) {
                if (null !== $field) {
                    $values[$field] = $row['values'][$header] ?? null;
                }
            }

            return ['line' => $row['line'], 'values' => $values];
        }, $rows);
    }

    private static function normalize(string $text): string
    {
        $ascii = \function_exists('transliterator_transliterate')
            ? (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text)
            : mb_strtolower($text);

        return trim((string) preg_replace('/[\s_\-]+/', ' ', $ascii));
    }
}
