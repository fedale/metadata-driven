<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Lifecycle;

use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\FormNodeDto;
use App\Module\Metadata\Contract\Dto\ManyToManySettings;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\ViewDefinitionDto;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataDependentInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Shared\Message\Message;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Where an object or a field is used by the rest of the metadata: what keeps it from being
 * archived. Each use is one line naming the place, for the admin to fix it: a Message, or the
 * line of another module as it gives it.
 *
 * What only describes the object itself (its label field, its searched fields, its own views
 * and translations) is no use: it follows the object, or is cleaned up with it. A field set
 * unique together is: archiving one of its fields would silently drop a rule on the data.
 *
 * The other modules' definitions naming objects and fields (the workflows) tell their own uses,
 * through the Metadata contract's MetadataDependentInterface.
 */
final class MetadataUsages
{
    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
        /** @var iterable<MetadataDependentInterface> */
        #[AutowireIterator('app.metadata_dependent')]
        private readonly iterable $dependents = [],
    ) {
    }

    /**
     * @return list<string|Message>
     */
    public function ofObject(string $objectName): array
    {
        $usages = [];
        foreach ($this->metadata->incomingRelations($objectName) as $reference) {
            if ($reference->object->name !== $objectName) {
                $usages[] = new Message('metadata.usage.relation_points_at', 'relation field "%object%.%field%" points at it', ['object' => $reference->object->name, 'field' => $reference->field->name]);
            }
        }
        foreach ($this->metadata->incomingManyToMany($objectName) as $reference) {
            if ($reference->object->name !== $objectName) {
                $usages[] = new Message('metadata.usage.many_to_many_points_at', 'many-to-many field "%object%.%field%" points at it', ['object' => $reference->object->name, 'field' => $reference->field->name]);
            }
        }
        foreach ($this->throughFields($objectName) as [$object, $field]) {
            $usages[] = new Message('metadata.usage.many_to_many_through', 'many-to-many field "%object%.%field%" goes through it', ['object' => $object->name, 'field' => $field->name]);
        }
        foreach ($this->metadata->allForms() as $form) {
            $paths = [];
            foreach (self::nodes($form->root) as $path => $node) {
                if ($node->object === $objectName) {
                    $paths[] = $path;
                }
            }
            if ([] !== $paths) {
                $usages[] = new Message('metadata.usage.form_places_object', 'form "%form%" (%paths%)', ['form' => $form->name, 'paths' => implode(', ', $paths)]);
            }
        }
        foreach ($this->dependents as $dependent) {
            $usages = [...$usages, ...$dependent->usesOfObject($objectName)];
        }

        return $usages;
    }

    /**
     * @return list<string|Message>
     */
    public function ofField(string $objectName, string $fieldName): array
    {
        $object = $this->metadata->getObject($objectName);
        $usages = [];
        foreach ($object->fields as $other) {
            if ($other->name === $fieldName) {
                continue;
            }
            if ($other->condition?->field === $fieldName) {
                $usages[] = new Message('metadata.usage.condition', 'field "%field%" is shown depending on it', ['field' => $other->name]);
            }
            if ($other->relation()?->dependsOn === $fieldName) {
                $usages[] = new Message('metadata.usage.cascade_parent', 'relation field "%field%" depends on it (cascade)', ['field' => $other->name]);
            }
        }
        foreach ($object->uniqueTogether as $set) {
            if (\in_array($fieldName, $set, true)) {
                $usages[] = new Message('metadata.usage.unique_together', 'it is unique together with %fields%', ['fields' => implode(', ', array_map(static fn (string $name): string => '"'.$name.'"', array_values(array_diff($set, [$fieldName]))))]);
            }
        }
        foreach ($this->throughFields($objectName) as [$through, $field, $settings]) {
            if (\in_array($fieldName, [$settings->throughSource, $settings->throughTarget], true)) {
                $usages[] = new Message('metadata.usage.many_to_many_through', 'many-to-many field "%object%.%field%" goes through it', ['object' => $through->name, 'field' => $field->name]);
            }
        }
        foreach ($this->metadata->incomingRelations($objectName) as $reference) {
            if ($reference->settings->dependsVia === $fieldName) {
                $usages[] = new Message('metadata.usage.cascade_via', 'relation field "%object%.%field%" filters through it (cascade)', ['object' => $reference->object->name, 'field' => $reference->field->name]);
            }
        }
        foreach ($object->views as $view) {
            if (self::viewUses($view, $fieldName)) {
                $usages[] = ViewKind::Grid === $view->kind
                    ? new Message('metadata.usage.grid_view', 'grid view "%view%"', ['view' => $view->name])
                    : new Message('metadata.usage.form_view', 'form view "%view%"', ['view' => $view->name]);
            }
        }
        foreach ($this->metadata->allForms() as $form) {
            foreach (self::nodes($form->root) as $path => $node) {
                if ($node->object !== $objectName) {
                    continue;
                }
                $how = array_keys(array_filter([
                    'fields' => \in_array($fieldName, $node->fields ?? [], true),
                    'required' => \in_array($fieldName, $node->required, true),
                    'layout' => \in_array($fieldName, $node->placed(), true),
                    'via' => $node->via === $fieldName,
                ]));
                if ([] !== $how) {
                    $usages[] = new Message('metadata.usage.form_places_field', 'form "%form%", %path%: %how%', ['form' => $form->name, 'path' => $path, 'how' => implode(', ', $how)]);
                }
            }
        }
        foreach ($this->dependents as $dependent) {
            $usages = [...$usages, ...$dependent->usesOfField($objectName, $fieldName)];
        }

        return $usages;
    }

    /**
     * The active many-to-many fields whose links are the records of a junction object.
     *
     * @return list<array{ObjectDefinitionDto, FieldDefinitionDto, ManyToManySettings}>
     */
    public function throughFields(string $junction): array
    {
        $fields = [];
        foreach ($this->metadata->allObjects() as $object) {
            foreach ($object->fields as $field) {
                $settings = $field->manyToMany();
                if (null !== $settings && $settings->through === $junction) {
                    $fields[] = [$object, $field, $settings];
                }
            }
        }

        return $fields;
    }

    private static function viewUses(ViewDefinitionDto $view, string $fieldName): bool
    {
        if (ViewKind::Form === $view->kind) {
            return \in_array($fieldName, ViewDefinitionDto::tokens($view->source), true);
        }
        foreach ($view->grid->columns ?? [] as $column) {
            if ($column->field === $fieldName || \in_array($fieldName, $column->sources, true)) {
                return true;
            }
        }

        return isset($view->grid?->sort[$fieldName]);
    }

    /**
     * Every node of a form, keyed by its path: "(root)", "profile", "profile.phones".
     *
     * @return iterable<string, FormNodeDto>
     */
    private static function nodes(FormNodeDto $node, string $path = ''): iterable
    {
        yield '' === $path ? '(root)' : $path => $node;
        foreach ($node->sections as $section) {
            yield from self::nodes($section, ('' === $path ? '' : $path.'.').$section->name);
        }
    }
}
