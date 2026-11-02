<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Lifecycle;

use App\Module\Metadata\Contract\Dto\FormNodeDto;
use App\Module\Metadata\Contract\Dto\ViewDefinitionDto;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataRegistryInterface;

/**
 * Where an object or a field is used by the rest of the metadata: what keeps it from being
 * archived. Each use is one line naming the place, for the admin to fix it.
 *
 * What only describes the object itself (its label field, its searched fields, its own views
 * and translations) is no use: it follows the object, or is cleaned up with it.
 */
final class MetadataUsages
{
    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
    ) {
    }

    /**
     * @return list<string>
     */
    public function ofObject(string $objectName): array
    {
        $usages = [];
        foreach ($this->metadata->incomingRelations($objectName) as $reference) {
            if ($reference->object->name !== $objectName) {
                $usages[] = \sprintf('relation field "%s.%s" points at it', $reference->object->name, $reference->field->name);
            }
        }
        foreach ($this->metadata->allForms() as $form) {
            $paths = [];
            foreach (self::nodes($form->root) as $path => $node) {
                if ($node->object === $objectName) {
                    $paths[] = $path;
                }
            }
            if ([] !== $paths) {
                $usages[] = \sprintf('composite form "%s" (%s)', $form->name, implode(', ', $paths));
            }
        }

        return $usages;
    }

    /**
     * @return list<string>
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
                $usages[] = \sprintf('field "%s" is shown depending on it', $other->name);
            }
            if ($other->relation()?->dependsOn === $fieldName) {
                $usages[] = \sprintf('relation field "%s" depends on it (cascade)', $other->name);
            }
        }
        foreach ($this->metadata->incomingRelations($objectName) as $reference) {
            if ($reference->settings->dependsVia === $fieldName) {
                $usages[] = \sprintf('relation field "%s.%s" filters through it (cascade)', $reference->object->name, $reference->field->name);
            }
        }
        foreach ($object->views as $view) {
            if (self::viewUses($view, $fieldName)) {
                $usages[] = \sprintf('%s view "%s"', $view->kind->value, $view->name);
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
                    $usages[] = \sprintf('composite form "%s", %s: %s', $form->name, $path, implode(', ', $how));
                }
            }
        }

        return $usages;
    }

    private static function viewUses(ViewDefinitionDto $view, string $fieldName): bool
    {
        if (ViewKind::Form === $view->kind) {
            return \in_array($fieldName, ViewDefinitionDto::tokens($view->template), true);
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
