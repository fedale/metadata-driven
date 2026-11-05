<?php

declare(strict_types=1);

namespace App\TemplateFormat;

/**
 * The ids and names of a package's documents, for resolving references; built from documents
 * that passed their schema. Duplicates are reported as problems.
 *
 * @internal used by PackageChecker
 */
final class PackageIndex
{
    /** @var list<Problem> */
    public readonly array $problems;

    /** @var array<string, true> object ids */
    private array $objects = [];

    /** @var array<string, string> field id => the id of its object */
    private array $fieldOwners = [];

    /**
     * @param list<Document> $documents
     */
    public function __construct(array $documents)
    {
        $problems = [];
        $ids = [];
        $names = [];
        foreach ($documents as $document) {
            // Ids are unique per kind: an object and a form may share one.
            $key = $document->kind->value.' '.$document->id;
            if (isset($ids[$key])) {
                $problems[] = new Problem($document->path, 'id', \sprintf('Already the id of %s.', $ids[$key]));
            }
            $ids[$key] ??= $document->path;

            $unique = self::uniqueName($document);
            if (null !== $unique) {
                [$name, $at] = $unique;
                if (isset($names[$name])) {
                    $problems[] = new Problem($document->path, $at, \sprintf('Same as in %s.', $names[$name]));
                }
                $names[$name] ??= $document->path;
            }

            if (DocumentKind::Object === $document->kind) {
                $this->objects[$document->id] = true;
                $problems = [...$problems, ...$this->indexFields($document)];
            }
        }
        $this->problems = $problems;
    }

    public function hasObject(string $id): bool
    {
        return isset($this->objects[$id]);
    }

    public function ownerOf(string $fieldId): ?string
    {
        return $this->fieldOwners[$fieldId] ?? null;
    }

    /**
     * @return list<Problem>
     */
    private function indexFields(Document $object): array
    {
        $problems = [];
        $names = [];
        /** @var list<array<string, mixed>> $fields the schema guarantees a list of maps */
        $fields = $object->spec['fields'];
        foreach ($fields as $position => $field) {
            $id = \is_string($field['id']) ? $field['id'] : '';
            $name = \is_string($field['name']) ? $field['name'] : '';
            // Field ids are unique across the package: references name a field by its id alone.
            if (isset($this->fieldOwners[$id])) {
                $problems[] = new Problem($object->path, \sprintf('spec.fields.%d.id', $position), \sprintf('Already the id of a field of "%s".', $this->fieldOwners[$id]));
            }
            if (isset($names[$name])) {
                $problems[] = new Problem($object->path, \sprintf('spec.fields.%d.name', $position), 'Another field of the object has this name.');
            }
            $this->fieldOwners[$id] ??= $object->id;
            $names[$name] = true;
        }

        return $problems;
    }

    /**
     * What must be unique in a package beyond ids: object names, form names, a view's name per
     * object and kind, one Translations document per language.
     *
     * @return array{string, string}|null the unique key, and where the document holds it
     */
    private static function uniqueName(Document $document): ?array
    {
        $spec = $document->spec;

        return match ($document->kind) {
            DocumentKind::Object, DocumentKind::Form => \is_string($spec['name'] ?? null) ? [$document->kind->value.' '.$spec['name'], 'spec.name'] : null,
            DocumentKind::View => \is_string($spec['object'] ?? null) && \is_string($spec['kind'] ?? null) && \is_string($spec['name'] ?? null)
                ? [\sprintf('View %s %s %s', $spec['object'], $spec['kind'], $spec['name']), 'spec.name']
                : null,
            DocumentKind::Translations => \is_string($spec['locale'] ?? null) ? ['Translations '.$spec['locale'], 'spec.locale'] : null,
            default => null,
        };
    }
}
