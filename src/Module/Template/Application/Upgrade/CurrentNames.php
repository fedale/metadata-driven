<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

use Fedale\TemplateFormat\DocumentKind;
use Fedale\TemplateFormat\FieldTypes\FieldTypeCatalog;
use Fedale\TemplateFormat\FieldTypes\ReferenceKind;
use Fedale\TemplateFormat\Package;

/**
 * The names the template's items have on the instance, by id, kept up to date while an
 * upgrade renames and adds them: the metadata API names items, the template ids them.
 *
 * @internal used by TemplateUpgrader
 */
final class CurrentNames
{
    /** @var array<string, string> object id => name */
    private array $objects = [];

    /** @var array<string, array{string, string}> field id => [object id, name] */
    private array $fields = [];

    /** @var array<string, string> form id => name */
    private array $forms = [];

    public function __construct(
        private readonly FieldTypeCatalog $fieldTypes,
    ) {
    }

    /**
     * Adds the names of a package's items, those already known kept.
     */
    public function learn(Package $package, bool $override = false): void
    {
        foreach ($package->documentsOf(DocumentKind::Object) as $object) {
            if (\is_string($object->spec['name'] ?? null) && ($override || !isset($this->objects[$object->id]))) {
                $this->objects[$object->id] = $object->spec['name'];
            }
            foreach (\is_array($object->spec['fields'] ?? null) ? $object->spec['fields'] : [] as $field) {
                if (\is_array($field) && \is_string($field['id'] ?? null) && \is_string($field['name'] ?? null) && ($override || !isset($this->fields[$field['id']]))) {
                    $this->fields[$field['id']] = [$object->id, $field['name']];
                }
            }
        }
        foreach ($package->documentsOf(DocumentKind::Form) as $form) {
            if (\is_string($form->spec['name'] ?? null) && ($override || !isset($this->forms[$form->id]))) {
                $this->forms[$form->id] = $form->spec['name'];
            }
        }
    }

    public function renameObject(string $id, string $name): void
    {
        $this->objects[$id] = $name;
    }

    public function renameField(string $id, string $objectId, string $name): void
    {
        $this->fields[$id] = [$objectId, $name];
    }

    public function object(string $id): string
    {
        return $this->objects[$id] ?? throw new \LogicException(\sprintf('No object has the id "%s".', $id));
    }

    public function field(string $id): string
    {
        return ($this->fields[$id] ?? throw new \LogicException(\sprintf('No field has the id "%s".', $id)))[1];
    }

    public function objectOfField(string $id): string
    {
        return $this->object(($this->fields[$id] ?? throw new \LogicException(\sprintf('No field has the id "%s".', $id)))[0]);
    }

    /**
     * A field's settings as the app takes them: the ids naming objects and fields turned into
     * their names.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public function settings(string $type, array $settings): array
    {
        foreach ($this->fieldTypes->references($type, $settings) as $reference) {
            $settings[$reference->setting] = ReferenceKind::Object === $reference->kind ? $this->object($reference->id) : $this->field($reference->id);
        }

        return $settings;
    }

    /**
     * A node of a composite form's definition, its object and via turned into names.
     *
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    public function definition(array $node): array
    {
        $node['object'] = $this->object(\is_string($node['object'] ?? null) ? $node['object'] : '');
        if (\is_string($node['via'] ?? null)) {
            $node['via'] = $this->field($node['via']);
        }
        if (\is_array($node['sections'] ?? null)) {
            $node['sections'] = array_map(fn (mixed $section): mixed => \is_array($section) ? $this->definition($section) : $section, $node['sections']);
        }

        return $node;
    }

    /**
     * A translation key compared by ids (ItemStates) back to the names it has now.
     */
    public function translationKey(string $normalized): string
    {
        $parts = explode('|', $normalized);
        $kind = $parts[0];
        if (\in_array($kind, ['object', 'field', 'inverse', 'message', 'option'], true) && isset($parts[1])) {
            if ('object' !== $kind && isset($parts[2], $this->fields[$parts[2]])) {
                $parts[2] = $this->fields[$parts[2]][1];
            }
            $parts[1] = $this->objects[$parts[1]] ?? $parts[1];
        } elseif (\in_array($kind, ['form', 'section', 'description', 'service'], true) && isset($parts[1])) {
            $parts[1] = $this->forms[$parts[1]] ?? $parts[1];
        }

        return implode('.', $parts);
    }
}
