<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Translation;

use App\Module\Metadata\Contract\Dto\FieldDefinitionDto;
use App\Module\Metadata\Contract\Dto\FormDefinitionDto;
use App\Module\Metadata\Contract\Dto\FormNodeDto;
use App\Module\Metadata\Contract\Dto\GridColumnDto;
use App\Module\Metadata\Contract\Dto\GridViewDto;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Metadata\Contract\Dto\RelationSettings;
use App\Module\Metadata\Contract\Dto\ServiceFieldDto;
use App\Module\Metadata\Contract\Dto\TranslationKey;
use App\Module\Metadata\Contract\Dto\ViewDefinitionDto;
use App\Module\Metadata\Contract\Dto\ViewKind;

/**
 * Copies of the definitions with every text in one language: the labels, the options, the
 * titles, and the {t:name} tokens of the layouts and of the grid headings replaced.
 *
 * Only the texts change: names, types, settings and structure stay as they are.
 */
final class MetadataTranslator
{
    public function object(ObjectDefinitionDto $object, TranslationLookup $lookup): ObjectDefinitionDto
    {
        $fields = [];
        foreach ($object->fields as $name => $field) {
            $fields[$name] = $this->field($object->name, $field, $lookup);
        }
        $views = [];
        foreach ($object->views as $view) {
            $views[] = new ViewDefinitionDto(
                $view->id,
                $view->kind,
                $view->name,
                ViewKind::Form === $view->kind ? $lookup->layout($view->template) : $view->template,
                $view->isDefault,
                null === $view->grid ? null : $this->grid($view->grid, $lookup),
            );
        }

        return new ObjectDefinitionDto(
            $object->id,
            $object->name,
            $lookup->translate(TranslationKey::objectSingular($object->name), $object->labelSingular),
            $lookup->translate(TranslationKey::objectPlural($object->name), $object->labelPlural),
            $object->tableName,
            $object->icon,
            $object->isSystem,
            $object->isActive,
            $fields,
            $object->searchHandler,
            $object->searchFields,
            $object->labelField,
            $views,
        );
    }

    public function form(FormDefinitionDto $form, TranslationLookup $lookup): FormDefinitionDto
    {
        $label = $lookup->translate(TranslationKey::form($form->name), $form->label);

        return new FormDefinitionDto($form->id, $form->name, $label, $this->node($form->name, $form->root, [], $label, $lookup), $form->source);
    }

    private function field(string $object, FieldDefinitionDto $field, TranslationLookup $lookup): FieldDefinitionDto
    {
        $settings = $field->settings;
        if (\is_array($settings['options'] ?? null)) {
            $options = [];
            foreach ($settings['options'] as $option) {
                if (\is_array($option) && \is_string($option['value'] ?? null) && \is_string($option['label'] ?? null)) {
                    $option['label'] = $lookup->translate(TranslationKey::option($object, $field->name, $option['value']), $option['label']);
                }
                $options[] = $option;
            }
            $settings['options'] = $options;
        }
        if (RelationSettings::FIELD_TYPE === $field->type && \is_string($settings['inverseLabel'] ?? null)) {
            $settings['inverseLabel'] = $lookup->translate(TranslationKey::inverse($object, $field->name), $settings['inverseLabel']);
        }
        if (\is_string($settings['patternMessage'] ?? null) && '' !== $settings['patternMessage']) {
            $settings['patternMessage'] = $lookup->translate(TranslationKey::patternMessage($object, $field->name), $settings['patternMessage']);
        }

        return new FieldDefinitionDto(
            $field->id,
            $field->name,
            $field->columnName,
            $field->type,
            $lookup->translate(TranslationKey::field($object, $field->name), $field->label),
            $field->isRequired,
            $field->isUnique,
            $settings,
            $field->position,
            $field->isSystem,
            $field->condition,
        );
    }

    private function grid(GridViewDto $grid, TranslationLookup $lookup): GridViewDto
    {
        return new GridViewDto(
            array_map(static fn (GridColumnDto $column): GridColumnDto => new GridColumnDto(
                $column->key,
                $column->field,
                $column->template,
                $column->sources,
                null === $column->label ? null : $lookup->plain($column->label),
                $column->renderer,
                $column->options,
                $column->hidden,
                $column->sortable,
                $column->filter,
            ), $grid->columns),
            $grid->sort,
            $grid->pageSize,
        );
    }

    /**
     * @param list<string> $path the section names from the root down to this node; [] for the root
     */
    private function node(string $form, FormNodeDto $node, array $path, string $label, TranslationLookup $lookup): FormNodeDto
    {
        $sections = [];
        foreach ($node->sections as $section) {
            $sectionPath = [...$path, $section->name];
            $sections[] = $this->node($form, $section, $sectionPath, $lookup->translate(TranslationKey::section($form, $sectionPath), $section->label), $lookup);
        }

        return new FormNodeDto(
            $node->name,
            $label,
            $node->object,
            $node->via,
            $node->mode,
            $node->min,
            $node->max,
            $node->fields,
            array_map(static fn (ServiceFieldDto $field): ServiceFieldDto => new ServiceFieldDto(
                $field->name,
                $field->type,
                $lookup->translate(TranslationKey::serviceField($form, $path, $field->name), $field->label),
                $field->isRequired,
                $field->settings,
                $field->ask,
            ), $node->serviceFields),
            $sections,
            $node->inline,
            $node->required,
            null === $node->layout ? null : $lookup->layout($node->layout),
            null === $node->description ? null : $lookup->translate(TranslationKey::description($form, $path), $node->description),
            $node->columns,
        );
    }
}
