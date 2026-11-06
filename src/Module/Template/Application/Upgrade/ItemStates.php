<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

use Fedale\TemplateFormat\Document;
use Fedale\TemplateFormat\DocumentKind;
use Fedale\TemplateFormat\FieldTypes\FieldTypeCatalog;
use Fedale\TemplateFormat\Package;

/**
 * A template's items, one by one, in a form that compares equal when they mean the same: the
 * installed version, the new one and what the instance holds now all go through it, and an
 * upgrade compares them item by item.
 *
 * The items: an object's own properties ("object:<id>"), each field ("field:<id>"), each view,
 * each form, and each translation ("translation:<locale>:<key>", its key naming objects, fields
 * and forms by id, so that a rename does not make it another item). Defaults are filled in and
 * lists that are sets are sorted: a template that leaves a default out equals one that writes it.
 */
final class ItemStates
{
    public function __construct(
        private readonly FieldTypeCatalog $fieldTypes,
    ) {
    }

    /**
     * @return array<string, ItemState> by item key
     */
    public function of(Package $package): array
    {
        $names = PackageIds::of($package);
        $states = [];
        foreach ($package->documents as $document) {
            foreach (match ($document->kind) {
                DocumentKind::Object => $this->object($document),
                DocumentKind::View => [new ItemState('view', $document->id, self::view($document->spec), $document)],
                DocumentKind::Form => [new ItemState('form', $document->id, ['name' => $document->spec['name'] ?? null, 'definition' => $document->spec['definition'] ?? null], $document)],
                DocumentKind::Translations => self::translations($document, $names),
                default => [],
            } as $state) {
                $states[$state->key()] = $state;
            }
        }

        return $states;
    }

    /**
     * @return list<ItemState>
     */
    private function object(Document $object): array
    {
        $spec = $object->spec;
        $search = \is_array($spec['search'] ?? null) ? $spec['search'] : null;
        $states = [new ItemState('object', $object->id, [
            'name' => $spec['name'] ?? null,
            'label' => $spec['label'] ?? null,
            'plural' => $spec['plural'] ?? null,
            'icon' => $spec['icon'] ?? null,
            'label_field' => $spec['label_field'] ?? null,
            'search' => null === $search ? null : ['handler' => $search['handler'] ?? null, 'fields' => $search['fields'] ?? null],
            // A set of sets: neither the order of the sets nor that of their fields matters.
            'unique_together' => self::sets(\is_array($spec['unique_together'] ?? null) ? $spec['unique_together'] : []),
        ], $object)];

        foreach (\is_array($spec['fields'] ?? null) ? $spec['fields'] : [] as $field) {
            if (!\is_array($field) || !\is_string($field['id'] ?? null)) {
                continue;
            }
            $type = \is_string($field['type'] ?? null) ? $field['type'] : '';
            $settings = [];
            foreach (\is_array($field['settings'] ?? null) ? $field['settings'] : [] as $key => $value) {
                $settings[(string) $key] = $value;
            }
            $settings = $this->fieldTypes->withoutDefaults($type, $settings);
            ksort($settings);
            $condition = \is_array($field['condition'] ?? null) ? $field['condition'] : null;
            $states[] = new ItemState('field', $field['id'], [
                'object' => $object->id,
                'name' => $field['name'] ?? null,
                'type' => $type,
                'label' => $field['label'] ?? null,
                'required' => true === ($field['required'] ?? false),
                'unique' => true === ($field['unique'] ?? false),
                'settings' => $settings,
                'condition' => null === $condition ? null : ['field' => $condition['field'] ?? null, 'values' => $condition['values'] ?? []],
            ], $object);
        }

        return $states;
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
     */
    private static function view(array $spec): array
    {
        return [
            'object' => $spec['object'] ?? null,
            'kind' => $spec['kind'] ?? null,
            'name' => $spec['name'] ?? null,
            'default' => true === ($spec['default'] ?? false),
            'template' => \is_string($spec['template'] ?? null) ? rtrim($spec['template']) : null,
        ];
    }

    /**
     * @return list<ItemState>
     */
    private static function translations(Document $document, PackageIds $names): array
    {
        $locale = \is_string($document->spec['locale'] ?? null) ? $document->spec['locale'] : '';
        $states = [];
        foreach (\is_array($document->spec['entries'] ?? null) ? $document->spec['entries'] : [] as $key => $text) {
            $states[] = new ItemState('translation', $locale.':'.$names->translationKey((string) $key), $text, $document, ['locale' => $locale, 'key' => (string) $key]);
        }

        return $states;
    }

    /**
     * @param array<mixed> $sets
     *
     * @return list<list<string>>
     */
    private static function sets(array $sets): array
    {
        $sorted = [];
        foreach ($sets as $set) {
            $set = array_values(array_filter(\is_array($set) ? $set : [], is_string(...)));
            sort($set);
            $sorted[] = $set;
        }
        usort($sorted, static fn (array $a, array $b): int => implode(',', $a) <=> implode(',', $b));

        return $sorted;
    }
}
