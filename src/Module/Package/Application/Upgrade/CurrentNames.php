<?php

declare(strict_types=1);

namespace App\Module\Package\Application\Upgrade;

use App\Module\Package\Application\RequiredItems;
use Fedale\PackageFormat\DocumentKind;
use Fedale\PackageFormat\FieldTypes\FieldTypeCatalog;
use Fedale\PackageFormat\FieldTypes\ReferenceKind;
use Fedale\PackageFormat\Package;

/**
 * The names the package's items have on the instance, by id, kept up to date while an
 * upgrade renames and adds them: the metadata API names items, the package ids them.
 *
 * @internal used by PackageUpgrader
 */
final class CurrentNames
{
    /** @var array<string, string> object id => name */
    private array $objects = [];

    /** @var array<string, array{string, string}> field id => [object id, name] */
    private array $fields = [];

    /** @var array<string, string> form id => name */
    private array $forms = [];

    /** @var array<string, string> workflow id => name */
    private array $workflows = [];

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
        foreach ($package->documentsOf(DocumentKind::Workflow) as $workflow) {
            if (\is_string($workflow->spec['name'] ?? null) && ($override || !isset($this->workflows[$workflow->id]))) {
                $this->workflows[$workflow->id] = $workflow->spec['name'];
            }
        }
    }

    /**
     * Adds the objects and fields of the packages it requires, as they are named here now: the
     * package references them by id, never renames or adds any.
     */
    public function learnRequired(RequiredItems $required): void
    {
        $objectIds = $required->objectIds();
        foreach ($objectIds as $name => $id) {
            $this->objects[$id] ??= $name;
        }
        foreach ($required->fieldIds() as $object => $fields) {
            foreach ($fields as $field => $id) {
                $this->fields[$id] ??= [$objectIds[$object], $field];
            }
        }
        foreach ($required->workflowIds() as $name => $id) {
            $this->workflows[$id] ??= $name;
        }
    }

    public function renameWorkflow(string $id, string $name): void
    {
        $this->workflows[$id] = $name;
    }

    public function workflow(string $id): string
    {
        return $this->workflows[$id] ?? throw new \LogicException(\sprintf('No workflow has the id "%s".', $id));
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

    /**
     * The name here of an object of the package, null when it has none yet (a new one).
     */
    public function ownedObject(string $id): ?string
    {
        return $this->objects[$id] ?? null;
    }

    /**
     * Whether a field of the package, on its object $objectId, is named $name here: the
     * package's own, not one in its way.
     */
    public function ownsField(string $objectId, string $name): bool
    {
        foreach ($this->fields as [$object, $field]) {
            if ($object === $objectId && $field === $name) {
                return true;
            }
        }

        return false;
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
     * A node of a form's definition, its object and via turned into names.
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
