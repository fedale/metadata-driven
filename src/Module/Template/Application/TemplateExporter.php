<?php

declare(strict_types=1);

namespace App\Module\Template\Application;

use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\FormDefinitionDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\TranslationKey;
use App\Module\Metadata\Contract\Dto\ViewDefinitionDto;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Contract\MetadataTranslationsInterface;
use App\Module\Template\Application\Command\ExportTemplateCommand;
use App\Module\Template\Domain\Exception\InvalidExport;
use App\Module\Template\Domain\Model\ItemKind;
use App\Module\Template\Domain\Repository\TemplateItemRepository;
use Fedale\TemplateFormat\Document;
use Fedale\TemplateFormat\DocumentKind;
use Fedale\TemplateFormat\FieldTypes\FieldOwner;
use Fedale\TemplateFormat\FieldTypes\FieldTypeCatalog;
use Fedale\TemplateFormat\FieldTypes\ReferenceKind;
use Fedale\TemplateFormat\Format;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\PackageChecker;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes objects, their fields and views, composite forms and their translations as a template.
 *
 * Inside the app items point at each other by name; in a template, by id. The ids recorded by a
 * previous export of the same template are kept, so that an object or a field renamed since keeps
 * its id: an upgrade then knows it was renamed, not replaced.
 */
final class TemplateExporter
{
    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
        private readonly MetadataTranslationsInterface $translations,
        private readonly TemplateItemRepository $items,
        private readonly FieldTypeCatalog $fieldTypes,
        private readonly PackageChecker $checker,
        private readonly SeedExporter $seedExporter,
        private readonly RequiredTemplates $required,
    ) {
    }

    /**
     * @throws InvalidExport     a name, a version, or an item pointing outside the export
     * @throws MetadataException an object or a form does not exist
     */
    public function export(ExportTemplateCommand $command): Package
    {
        if (1 !== preg_match(Format::NAME_PATTERN, $command->name)) {
            throw InvalidExport::invalidName($command->name);
        }
        if (1 !== preg_match(Format::VERSION_PATTERN, $command->version)) {
            throw InvalidExport::invalidVersion($command->version);
        }
        if ([] === $command->objects && [] === $command->forms) {
            throw InvalidExport::nothingToExport();
        }

        $objects = [];
        foreach (array_unique($command->objects) as $name) {
            $objects[$name] = $this->metadata->getObject($name);
        }
        $forms = [];
        foreach (array_unique($command->forms) as $name) {
            $form = $this->metadata->getForm($name);
            foreach ($form->root->objects() as $object) {
                if (!isset($objects[$object])) {
                    throw InvalidExport::formOutsideTheExport($name, $object);
                }
            }
            $forms[$name] = $form;
        }
        foreach ($objects as $object) {
            foreach ($object->fields as $field) {
                foreach ($this->fieldTypes->references($field->type, $field->settings) as $reference) {
                    if (ReferenceKind::Object === $reference->kind && !isset($objects[$reference->id])) {
                        throw InvalidExport::outsideTheExport($object->name, $field->name.'.'.$reference->setting, $reference->id);
                    }
                }
            }
        }

        // Every id first, so that a reference can point at an object exported after its own.
        $ids = new ItemIds($command->name, $this->items->itemsOf($command->name));
        $prefix = Format::idPrefix($command->name);
        $objectIds = [];
        $fieldIds = [];
        foreach ($objects as $name => $object) {
            $objectIds[$name] = $ids->of(ItemKind::Object, $object->id, $prefix.'.'.$name);
            foreach ($object->fields as $fieldName => $field) {
                $fieldIds[$name][$fieldName] = $ids->of(ItemKind::Field, $field->id, $objectIds[$name].'.'.$fieldName);
            }
        }
        $refs = new ItemReferences($objectIds, $fieldIds);

        $documents = [];
        foreach ($objects as $object) {
            $documents[] = $this->objectDocument($object, $refs);
            foreach ($object->views as $view) {
                $id = $ids->of(ItemKind::View, $view->id, \sprintf('%s.%s.%s', $refs->object($object->name), $view->kind->value, $view->name));
                $documents[] = $this->viewDocument($object, $view, $id, $refs);
            }
        }
        foreach ($forms as $form) {
            $documents[] = $this->formDocument($form, $ids->of(ItemKind::Form, $form->id, $prefix.'.'.$form->name), $refs);
        }
        $documents = [...$documents, ...$this->translationDocuments($prefix, $objects, $forms)];
        [$seeds, $files] = $this->seedExporter->export($prefix, $objects, $command->seeds, $refs);
        $documents = [...$documents, ...$seeds];

        $package = new Package($this->manifest($command, $documents), $documents, null, $files);
        $problems = $this->checker->check($package);
        if ([] !== $problems) {
            throw InvalidExport::brokenPackage(array_map(strval(...), $problems));
        }
        $this->items->add($ids->newItems());

        return $package;
    }

    /**
     * What this instance holds of an installed template, written as the template would be: the
     * objects, fields, views and forms it owns, under the ids recorded when it was installed, and
     * their translations. Nothing is recorded. A field the user added to one of its objects is
     * left out; a reference the user pointed at an item it does not own is "local:<name>", which
     * no id of a template equals. A reference to an item of a template it requires is written with
     * that item's id, as the template itself writes it.
     *
     * @param list<string>|null $requires the templates it requires; null for those its installed
     *                                    version requires (an install passes them: its installation
     *                                    is not recorded yet)
     */
    public function ownedState(string $template, ?array $requires = null): Package
    {
        $owned = [];
        foreach ($this->items->itemsOf($template) as $item) {
            $owned[$item->getKind()->value][$item->getLocalId()] = $item->getItemId();
        }

        $objects = [];
        $objectIds = [];
        $fieldIds = [];
        foreach ($this->metadata->allObjects() as $name => $object) {
            if (!isset($owned[ItemKind::Object->value][$object->id])) {
                continue;
            }
            $objects[$name] = $object;
            $objectIds[$name] = $owned[ItemKind::Object->value][$object->id];
            foreach ($object->fields as $fieldName => $field) {
                if (isset($owned[ItemKind::Field->value][$field->id])) {
                    $fieldIds[$name][$fieldName] = $owned[ItemKind::Field->value][$field->id];
                }
            }
        }
        // The template's own items win: the checker has made sure no id is in both.
        $required = $this->required->items($requires ?? $this->required->requiredBy($template));
        $refs = new ItemReferences($objectIds + $required->objectIds(), $fieldIds + $required->fieldIds(), strict: false);

        $documents = [];
        foreach ($objects as $name => $object) {
            $documents[] = $this->objectDocument($object, $refs, array_keys($fieldIds[$name] ?? []));
            foreach ($object->views as $view) {
                if (isset($owned[ItemKind::View->value][$view->id])) {
                    $documents[] = $this->viewDocument($object, $view, $owned[ItemKind::View->value][$view->id], $refs);
                }
            }
        }
        $forms = [];
        foreach ($this->metadata->allForms() as $name => $form) {
            if (isset($owned[ItemKind::Form->value][$form->id])) {
                $forms[$name] = $form;
                $documents[] = $this->formDocument($form, $owned[ItemKind::Form->value][$form->id], $refs);
            }
        }
        $documents = [...$documents, ...$this->translationDocuments(Format::idPrefix($template), $objects, $forms)];

        return new Package(new Document(DocumentKind::Template, $template, [], Format::MANIFEST), $documents);
    }

    /**
     * @param list<string>|null $fields the fields written, by name; null for every one
     */
    private function objectDocument(ObjectDefinitionDto $object, ItemReferences $refs, ?array $fields = null): Document
    {
        $spec = ['name' => $object->name, 'label' => $object->labelSingular, 'plural' => $object->labelPlural];
        if (null !== $object->icon) {
            $spec['icon'] = $object->icon;
        }
        if (null !== $object->labelField) {
            $spec['label_field'] = $refs->field($object->name, $object->labelField);
        }
        if (null !== $object->searchHandler || null !== $object->searchFields) {
            $spec['search'] = [
                'handler' => $object->searchHandler,
                'fields' => null === $object->searchFields ? null : array_map(static fn (string $field): string => $refs->field($object->name, $field), $object->searchFields),
            ];
        }
        if ([] !== $object->uniqueTogether) {
            $spec['unique_together'] = array_map(
                static fn (array $set): array => array_map(static fn (string $field): string => $refs->field($object->name, $field), $set),
                $object->uniqueTogether,
            );
        }
        $written = null === $fields ? $object->fields : array_intersect_key($object->fields, array_flip($fields));
        uasort($written, static fn (FieldDefinitionDto $a, FieldDefinitionDto $b): int => $a->position <=> $b->position);
        $spec['fields'] = array_values(array_map(fn (FieldDefinitionDto $field): array => $this->fieldSpec($object, $field, $refs), $written));

        return new Document(DocumentKind::Object, $refs->object($object->name), $spec, \sprintf('objects/%s.yaml', $object->name));
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldSpec(ObjectDefinitionDto $object, FieldDefinitionDto $field, ItemReferences $refs): array
    {
        $spec = ['id' => $refs->field($object->name, $field->name), 'name' => $field->name, 'type' => $field->type, 'label' => $field->label];
        // Only what differs from the defaults: a template stays short enough to read and edit.
        if ($field->isRequired) {
            $spec['required'] = true;
        }
        if ($field->isUnique) {
            $spec['unique'] = true;
        }
        $settings = $this->fieldTypes->withoutDefaults($field->type, $this->settings($object, $field, $refs));
        if ([] !== $settings) {
            $spec['settings'] = $settings;
        }
        if (null !== $field->condition) {
            $spec['condition'] = ['field' => $refs->field($object->name, $field->condition->field), 'values' => $field->condition->values];
        }

        return $spec;
    }

    /**
     * The settings of a field, those naming an object or a field turned into ids.
     *
     * @return array<string, mixed>
     */
    private function settings(ObjectDefinitionDto $object, FieldDefinitionDto $field, ItemReferences $refs): array
    {
        $settings = $field->settings;
        foreach ($this->fieldTypes->references($field->type, $field->settings) as $reference) {
            // The owner is read from the app's settings, where "target" is still a name.
            $settings[$reference->setting] = ReferenceKind::Object === $reference->kind
                ? $refs->object($reference->id)
                : $refs->field(match ($reference->of) {
                    FieldOwner::Own, null => $object->name,
                    FieldOwner::Target => self::nameSetting($field, 'target'),
                    FieldOwner::Through => self::nameSetting($field, 'through'),
                }, $reference->id);
        }

        return $settings;
    }

    private function viewDocument(ObjectDefinitionDto $object, ViewDefinitionDto $view, string $id, ItemReferences $refs): Document
    {
        return new Document(DocumentKind::View, $id, [
            'object' => $refs->object($object->name),
            'kind' => $view->kind->value,
            'name' => $view->name,
            'default' => $view->isDefault,
            // As the app stores it: the sanitized HTML of a form view, the YAML of a grid view.
            // Both name fields by name, within their object.
            'template' => $view->template,
        ], \sprintf('views/%s.%s.%s.yaml', $object->name, $view->kind->value, $view->name));
    }

    private function formDocument(FormDefinitionDto $form, string $id, ItemReferences $refs): Document
    {
        // The definition as written (its YAML comments do not survive), objects and vias as ids.
        $definition = Yaml::parse($form->source);

        return new Document(DocumentKind::Form, $id, [
            'name' => $form->name,
            // The app compiled this source when the form was saved: it is a map.
            'definition' => self::withIds(\is_array($definition) ? $definition : [], $refs),
        ], \sprintf('forms/%s.yaml', $form->name));
    }

    /**
     * @param array<mixed> $node a node of the definition: the root, or a section
     *
     * @return array<mixed>
     */
    private static function withIds(array $node, ItemReferences $refs): array
    {
        $object = \is_string($node['object'] ?? null) ? $node['object'] : '';
        $node['object'] = $refs->object($object);
        if (\is_string($node['via'] ?? null)) {
            $node['via'] = $refs->field($object, $node['via']);
        }
        if (\is_array($node['sections'] ?? null)) {
            $node['sections'] = array_map(
                static fn (mixed $section): mixed => \is_array($section) ? self::withIds($section, $refs) : $section,
                $node['sections'],
            );
        }

        return $node;
    }

    /**
     * One document per language that has something to say about the exported items: their
     * translations, and the {t:name} texts their layouts use (in the default language too, where
     * a text has no other place).
     *
     * @param array<string, ObjectDefinitionDto> $objects
     * @param array<string, FormDefinitionDto>   $forms
     *
     * @return list<Document>
     */
    private function translationDocuments(string $prefix, array $objects, array $forms): array
    {
        $used = [];
        foreach ($objects as $object) {
            foreach ($object->views as $view) {
                $used = [...$used, ...ViewDefinitionDto::textTokens($view->template)];
            }
        }
        foreach ($forms as $form) {
            $used = [...$used, ...ViewDefinitionDto::textTokens($form->source)];
        }
        $used = array_flip($used);

        $entries = [];
        foreach ($this->translations->texts() as $text) {
            if (isset($used[$text->name])) {
                foreach ($text->texts as $locale => $value) {
                    $entries[$locale][TranslationKey::text($text->name)] = $value;
                }
            }
        }
        $default = $this->translations->defaultLocale();
        foreach ($this->translations->locales() as $locale) {
            if ($locale === $default) {
                continue;
            }
            foreach ($this->translations->entries($locale) as $entry) {
                if (null !== $entry->translation && !$entry->isText() && self::belongsTo($entry->key, $objects, $forms)) {
                    $entries[$locale][$entry->key] = $entry->translation;
                }
            }
        }

        $documents = [];
        foreach ($this->translations->locales() as $locale) {
            if ([] === ($entries[$locale] ?? [])) {
                continue;
            }
            ksort($entries[$locale]);
            $documents[] = new Document(DocumentKind::Translations, \sprintf('%s.translations.%s', $prefix, strtolower($locale)), [
                'locale' => $locale,
                'entries' => $entries[$locale],
            ], \sprintf('translations/%s.yaml', $locale));
        }

        return $documents;
    }

    /**
     * @param array<string, ObjectDefinitionDto> $objects
     * @param array<string, FormDefinitionDto>   $forms
     */
    private static function belongsTo(string $key, array $objects, array $forms): bool
    {
        $prefixes = [];
        foreach (array_keys($objects) as $object) {
            $prefixes = [...$prefixes, ...TranslationKey::objectPrefixes($object)];
        }
        foreach (array_keys($forms) as $form) {
            $prefixes = [...$prefixes, TranslationKey::form($form), 'section.'.$form, 'description.'.$form, 'service.'.$form];
        }
        foreach ($prefixes as $prefix) {
            if ($key === $prefix || str_starts_with($key, $prefix.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Document> $documents
     */
    private function manifest(ExportTemplateCommand $command, array $documents): Document
    {
        $directories = array_values(array_unique(array_map(static fn (Document $document): string => $document->kind->directory(), $documents)));
        $spec = [
            'version' => $command->version,
            'label' => $command->label ?? ucfirst(str_replace('-', ' ', explode('/', $command->name)[1])),
        ];
        if (null !== $command->description) {
            $spec['description'] = $command->description;
        }

        return new Document(DocumentKind::Template, $command->name, $spec + [
            'license' => $command->license,
            'requires' => ['format' => Format::VERSION],
            'resources' => array_map(static fn (string $directory): string => $directory.'/*.yaml', $directories),
        ], Format::MANIFEST);
    }

    private static function nameSetting(FieldDefinitionDto $field, string $key): string
    {
        $value = $field->settings[$key] ?? null;

        return \is_string($value) ? $value : throw new \LogicException(\sprintf('Field "%s" names no object in its setting "%s".', $field->name, $key));
    }
}
