<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Form;

use App\Module\Metadata\Contract\Dto\FormDefinitionDto;
use App\Module\Metadata\Contract\Dto\FormNodeDto;
use App\Module\Metadata\Contract\Dto\FormNodeMode;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\CompositeNodeInput;
use App\Module\Record\Contract\Dto\CompositeRecord;
use App\Module\Record\Contract\Exception\CompositeValidationFailed;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Builds the form of a composite form definition, and converts between its data and the
 * records: CompositeRecord → form data → CompositeNodeInput.
 *
 * The data of a node: ['id' => ?string, 'values' => [...], 'service' => [...],
 * 'sections' => [name => node data, or a list of row node data]].
 */
final class CompositeFormFactory
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        // The labels in the user's language.
        #[Target('localizedMetadata')]
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordFormFactory $forms,
    ) {
    }

    /**
     * @return FormInterface<array<string, mixed>>
     */
    public function create(FormDefinitionDto $form, ?CompositeRecord $record, bool $readOnly = false): FormInterface
    {
        return $this->formFactory->createNamed('composite', CompositeNodeType::class, $this->data($form->root, $record), [
            'node' => $form->root,
            'label' => false,
            'disabled' => $readOnly,
        ]);
    }

    /**
     * The data of a node: the record's values, or the default values for a new one; a new
     * one-to-many section starts with its minimum number of rows.
     *
     * @return array<string, mixed>
     */
    public function data(FormNodeDto $node, ?CompositeRecord $record): array
    {
        $sections = [];
        foreach ($node->sections as $section) {
            $current = $record?->sections[$section->name] ?? null;
            if (FormNodeMode::One === $section->mode) {
                $sections[$section->name] = $this->data($section, $current instanceof CompositeRecord ? $current : null);
                continue;
            }
            $rows = array_map(fn (CompositeRecord $row): array => $this->data($section, $row), \is_array($current) ? $current : []);
            while (\count($rows) < $section->min) {
                $rows[] = $this->data($section, null);
            }
            $sections[$section->name] = $rows;
        }

        // Only the fields the node shows: the "via" field is the form's business.
        $object = $this->metadata->getObject($node->object);
        $values = array_intersect_key($record->record->values ?? $this->forms->defaults($object), array_flip($node->fieldNames($object)));

        return [
            'id' => $record?->record->id,
            'values' => $values,
            'service' => [],
            'sections' => $sections,
        ];
    }

    /**
     * The submitted data of a node as the input of the composite record manager.
     */
    public function input(FormNodeDto $node, mixed $data): CompositeNodeInput
    {
        $data = \is_array($data) ? $data : [];
        $sections = [];
        foreach ($node->sections as $section) {
            $sectionData = \is_array($data['sections'] ?? null) ? ($data['sections'][$section->name] ?? null) : null;
            if (FormNodeMode::One === $section->mode) {
                $sections[$section->name] = $this->input($section, $sectionData);
                continue;
            }
            $rows = [];
            foreach (\is_array($sectionData) ? $sectionData : [] as $key => $row) {
                $rows[$key] = $this->input($section, $row);
            }
            $sections[$section->name] = $rows;
        }
        $id = $data['id'] ?? null;
        /** @var array<string, mixed> $values keyed by field name, as the form builds them */
        $values = \is_array($data['values'] ?? null) ? $data['values'] : [];
        // Links through a junction object are only shown (disabled): they are not input.
        $object = $this->metadata->getObject($node->object);
        $values = array_filter($values, static fn (string $name): bool => !$object->hasField($name) || null === $object->field($name)->manyToMany()?->through, \ARRAY_FILTER_USE_KEY);
        /** @var array<string, mixed> $service keyed by service field name */
        $service = \is_array($data['service'] ?? null) ? $data['service'] : [];

        return new CompositeNodeInput(\is_string($id) && '' !== $id ? $id : null, $values, $service, $sections);
    }

    /**
     * Puts each error on the input its path names ("sections.profile.values.city"), or on the
     * nearest form that exists along that path.
     *
     * @template T
     *
     * @param FormInterface<T> $form
     */
    public function addErrors(FormInterface $form, CompositeValidationFailed $failure): void
    {
        foreach ($failure->errors as $path => $messages) {
            $target = $form;
            foreach ('' === $path ? [] : explode('.', $path) as $segment) {
                if (!$target->has($segment)) {
                    break;
                }
                $target = $target->get($segment);
            }
            foreach ($messages as $message) {
                $target->addError(new FormError($message));
            }
        }
    }
}
