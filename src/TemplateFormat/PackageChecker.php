<?php

declare(strict_types=1);

namespace App\TemplateFormat;

use App\TemplateFormat\FieldTypes\FieldOwner;
use App\TemplateFormat\FieldTypes\FieldTypeCatalog;
use App\TemplateFormat\FieldTypes\ReferenceKind;

/**
 * The structural checks of a package, without the app: each document against its schema, the
 * settings of every field against the field type catalog, unique ids and names, and every
 * reference naming an item of the template.
 *
 * What only the app can tell (a composite form that does not fit its objects, a grid view naming
 * a field that cannot be shown) is checked when the template is installed.
 */
final class PackageChecker
{
    public function __construct(
        private readonly SchemaValidator $schemas,
        private readonly FieldTypeCatalog $fieldTypes,
    ) {
    }

    /**
     * @return list<Problem> every problem found; empty when the package is valid
     */
    public function check(Package $package): array
    {
        $problems = $this->schemas->validate($package->manifest);
        $valid = [];
        foreach ($package->documents as $document) {
            $found = DocumentKind::Template === $document->kind
                ? [new Problem($document->path, 'kind', 'A package has one manifest, '.Format::MANIFEST.'.')]
                : $this->schemas->validate($document);
            $problems = [...$problems, ...$found];
            // Documents that break their schema are not checked further: their shape is unknown.
            if ([] === $found) {
                $valid[] = $document;
            }
        }

        $index = new PackageIndex($valid);
        $problems = [...$problems, ...$index->problems];
        foreach ($valid as $document) {
            $problems = [...$problems, ...match ($document->kind) {
                DocumentKind::Object => $this->checkObject($document, $index),
                DocumentKind::View => $this->checkView($document, $index),
                DocumentKind::Form => $this->checkForm($document, $index),
                default => [],
            }];
        }

        return $problems;
    }

    /**
     * @return list<Problem>
     */
    private function checkObject(Document $object, PackageIndex $index): array
    {
        $problems = [];
        /** @var list<array<string, mixed>> $fields the schema guarantees a list of maps */
        $fields = $object->spec['fields'];
        foreach ($fields as $position => $field) {
            $at = \sprintf('spec.fields.%d', $position);
            $type = \is_string($field['type']) ? $field['type'] : '';
            /** @var array<string, mixed> $settings */
            $settings = \is_array($field['settings'] ?? null) ? $field['settings'] : [];
            foreach ($this->fieldTypes->check($type, $settings) as $key => $messages) {
                foreach ($messages as $message) {
                    $problems[] = new Problem($object->path, '' === $key ? $at.'.type' : $at.'.settings.'.$key, $message);
                }
            }
            foreach ($this->fieldTypes->references($type, $settings) as $reference) {
                $message = ReferenceKind::Object === $reference->kind
                    ? ($index->hasObject($reference->id) ? null : \sprintf('No object of the template has the id "%s".', $reference->id))
                    : $this->fieldReferenceProblem($reference->id, $reference->of, $object->id, $settings, $index);
                if (null !== $message) {
                    $problems[] = new Problem($object->path, $at.'.settings.'.$reference->setting, $message);
                }
            }
            $condition = $field['condition'] ?? null;
            if (\is_array($condition) && \is_string($condition['field'] ?? null)) {
                $message = $condition['field'] === $field['id']
                    ? 'A field cannot depend on itself.'
                    : $this->ownFieldProblem($condition['field'], $object->id, $index);
                if (null !== $message) {
                    $problems[] = new Problem($object->path, $at.'.condition.field', $message);
                }
            }
        }

        $own = [];
        if (\is_string($object->spec['label_field'] ?? null)) {
            $own['spec.label_field'] = $object->spec['label_field'];
        }
        $search = $object->spec['search'] ?? null;
        foreach (\is_array($search) && \is_array($search['fields'] ?? null) ? $search['fields'] : [] as $i => $id) {
            $own['spec.search.fields.'.$i] = $id;
        }
        foreach (\is_array($object->spec['unique_together'] ?? null) ? $object->spec['unique_together'] : [] as $i => $set) {
            foreach (\is_array($set) ? $set : [] as $j => $id) {
                $own[\sprintf('spec.unique_together.%d.%d', $i, $j)] = $id;
            }
        }
        foreach ($own as $at => $id) {
            $message = \is_string($id) ? $this->ownFieldProblem($id, $object->id, $index) : null;
            if (null !== $message) {
                $problems[] = new Problem($object->path, $at, $message);
            }
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $settings the settings of the field holding the reference
     */
    private function fieldReferenceProblem(string $id, ?FieldOwner $of, string $objectId, array $settings, PackageIndex $index): ?string
    {
        $owner = match ($of) {
            FieldOwner::Own, null => $objectId,
            FieldOwner::Target => \is_string($settings['target'] ?? null) ? $settings['target'] : null,
            FieldOwner::Through => \is_string($settings['through'] ?? null) ? $settings['through'] : null,
        };
        if (null === $owner) {
            return \sprintf('Names a field of the object in the setting "%s", which is not set.', FieldOwner::Target === $of ? 'target' : 'through');
        }

        return $this->ownFieldProblem($id, $owner, $index);
    }

    private function ownFieldProblem(string $fieldId, string $objectId, PackageIndex $index): ?string
    {
        $owner = $index->ownerOf($fieldId);
        if (null === $owner) {
            return \sprintf('No field of the template has the id "%s".', $fieldId);
        }

        return $owner === $objectId ? null : \sprintf('"%s" is a field of "%s", not of "%s".', $fieldId, $owner, $objectId);
    }

    /**
     * @return list<Problem>
     */
    private function checkView(Document $view, PackageIndex $index): array
    {
        $object = $view->spec['object'];

        return \is_string($object) && !$index->hasObject($object)
            ? [new Problem($view->path, 'spec.object', \sprintf('No object of the template has the id "%s".', $object))]
            : [];
    }

    /**
     * @return list<Problem>
     */
    private function checkForm(Document $form, PackageIndex $index): array
    {
        /** @var array<string, mixed> $definition the schema guarantees a map */
        $definition = $form->spec['definition'];

        return $this->checkFormNode($form->path, 'spec.definition', $definition, $index);
    }

    /**
     * A node of a composite form: its object, and for a section the relation field (via) of
     * that object pointing at the parent's.
     *
     * @param array<string, mixed> $node
     *
     * @return list<Problem>
     */
    private function checkFormNode(string $path, string $at, array $node, PackageIndex $index): array
    {
        $object = $node['object'] ?? null;
        if (!\is_string($object) || !$index->hasObject($object)) {
            return [new Problem($path, $at.'.object', \sprintf('Must be the id of an object of the template%s.', \is_string($object) ? \sprintf(', "%s" is none', $object) : ''))];
        }

        $problems = [];
        $via = $node['via'] ?? null;
        if (\is_string($via)) {
            $message = $this->ownFieldProblem($via, $object, $index);
            if (null !== $message) {
                $problems[] = new Problem($path, $at.'.via', $message);
            }
        }
        foreach (\is_array($node['sections'] ?? null) ? $node['sections'] : [] as $i => $section) {
            if (!\is_array($section)) {
                $problems[] = new Problem($path, $at.'.sections.'.$i, 'A section is a map.');
                continue;
            }
            if (!\is_string($section['via'] ?? null)) {
                $problems[] = new Problem($path, $at.'.sections.'.$i.'.via', 'Must be the id of the relation field of the section\'s object pointing at its parent.');
            }
            /** @var array<string, mixed> $section */
            $problems = [...$problems, ...$this->checkFormNode($path, $at.'.sections.'.$i, $section, $index)];
        }

        return $problems;
    }
}
