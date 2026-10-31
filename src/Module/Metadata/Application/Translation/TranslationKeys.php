<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Translation;

use App\Module\Metadata\Contract\Dto\FormNodeDto;
use App\Module\Metadata\Contract\Dto\RelationSettings;
use App\Module\Metadata\Contract\Dto\TranslationKey;
use App\Module\Metadata\Contract\Dto\ViewDefinitionDto;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataRegistryInterface;

/**
 * The keys derived from the metadata, with their text in the default language (the metadata
 * itself), and where the {t:name} tokens are used.
 */
final class TranslationKeys
{
    public function __construct(
        private readonly MetadataRegistryInterface $metadata,
    ) {
    }

    /**
     * @return array<string, array{group: string, source: string}> by key, in the order of the metadata
     */
    public function derived(): array
    {
        $keys = [];
        foreach ($this->metadata->allObjects() as $object) {
            $group = 'Object '.$object->name;
            $keys[TranslationKey::objectSingular($object->name)] = ['group' => $group, 'source' => $object->labelSingular];
            $keys[TranslationKey::objectPlural($object->name)] = ['group' => $group, 'source' => $object->labelPlural];
            foreach ($object->fields as $field) {
                $keys[TranslationKey::field($object->name, $field->name)] = ['group' => $group, 'source' => $field->label];
                foreach (\is_array($field->settings['options'] ?? null) ? $field->settings['options'] : [] as $option) {
                    if (\is_array($option) && \is_string($option['value'] ?? null) && \is_string($option['label'] ?? null)) {
                        $keys[TranslationKey::option($object->name, $field->name, $option['value'])] = ['group' => $group, 'source' => $option['label']];
                    }
                }
                if (RelationSettings::FIELD_TYPE === $field->type && \is_string($field->settings['inverseLabel'] ?? null)) {
                    $keys[TranslationKey::inverse($object->name, $field->name)] = ['group' => $group, 'source' => $field->settings['inverseLabel']];
                }
                if (\is_string($field->settings['patternMessage'] ?? null) && '' !== $field->settings['patternMessage']) {
                    $keys[TranslationKey::patternMessage($object->name, $field->name)] = ['group' => $group, 'source' => $field->settings['patternMessage']];
                }
            }
        }
        foreach ($this->metadata->allForms() as $form) {
            $group = 'Form '.$form->name;
            $keys[TranslationKey::form($form->name)] = ['group' => $group, 'source' => $form->label];
            $this->node($keys, $group, $form->name, $form->root, []);
        }

        return $keys;
    }

    /**
     * Where each text is placed, by text name: "view pet/main", "grid view pet/main", "form registration".
     *
     * @return array<string, list<string>>
     */
    public function textUsages(): array
    {
        $usages = [];
        foreach ($this->metadata->allObjects() as $object) {
            foreach ($object->views as $view) {
                $where = \sprintf('%s view %s/%s', $view->kind->value, $object->name, $view->name);
                $tokens = ViewKind::Form === $view->kind ? ViewDefinitionDto::textTokens($view->template) : [];
                foreach ($view->grid->columns ?? [] as $column) {
                    $tokens = [...$tokens, ...ViewDefinitionDto::textTokens($column->label ?? '')];
                }
                foreach (array_unique($tokens) as $name) {
                    $usages[$name][] = $where;
                }
            }
        }
        foreach ($this->metadata->allForms() as $form) {
            foreach (array_unique($this->nodeTokens($form->root)) as $name) {
                $usages[$name][] = 'form '.$form->name;
            }
        }

        return $usages;
    }

    /**
     * @param array<string, array{group: string, source: string}> $keys
     * @param list<string>                                        $path
     */
    private function node(array &$keys, string $group, string $form, FormNodeDto $node, array $path): void
    {
        foreach ($node->serviceFields as $field) {
            $keys[TranslationKey::serviceField($form, $path, $field->name)] = ['group' => $group, 'source' => $field->label];
        }
        foreach ($node->sections as $section) {
            $sectionPath = [...$path, $section->name];
            $keys[TranslationKey::section($form, $sectionPath)] = ['group' => $group, 'source' => $section->label];
            $this->node($keys, $group, $form, $section, $sectionPath);
        }
    }

    /**
     * @return list<string>
     */
    private function nodeTokens(FormNodeDto $node): array
    {
        $tokens = ViewDefinitionDto::textTokens($node->layout ?? '');
        foreach ($node->sections as $section) {
            $tokens = [...$tokens, ...$this->nodeTokens($section)];
        }

        return $tokens;
    }
}
