<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Form;

use App\Module\Metadata\Contract\Dto\FormNodeDto;
use App\Module\Metadata\Contract\Dto\FormNodeMode;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Event\PreSetDataEvent;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One node of a composite form: "values" (the fields of its record), "service" (its service
 * fields), "sections" (one child per section: a node, or a collection of row nodes) and, for a
 * row, its hidden record "id".
 *
 * The data is an array of that shape; see CompositeFormFactory.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class CompositeNodeType extends AbstractType
{
    public function __construct(
        // The labels in the user's language.
        #[Target('localizedMetadata')]
        private readonly MetadataRegistryInterface $metadata,
        private readonly RecordFormFactory $forms,
        private readonly CompositeFormFactory $composite,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var FormNodeDto $node */
        $node = $options['node'];
        $object = $this->metadata->getObject($node->object);

        if (FormNodeMode::Many === $node->mode) {
            $builder->add('id', HiddenType::class);
        }
        if ([] !== $node->serviceFields) {
            $service = $builder->create('service', FormType::class, ['label' => false, 'error_bubbling' => false]);
            foreach ($node->serviceFields as $field) {
                $this->forms->addServiceControl($service, $field);
            }
            $builder->add($service);
        }
        if ([] !== $node->sections) {
            $sections = $builder->create('sections', FormType::class, ['label' => false, 'error_bubbling' => false]);
            foreach ($node->sections as $section) {
                if (FormNodeMode::One === $section->mode) {
                    $sections->add($section->name, self::class, ['node' => $section, 'label' => $section->label]);
                    continue;
                }
                $sections->add($section->name, CollectionType::class, [
                    'label' => $section->label,
                    'entry_type' => self::class,
                    'entry_options' => ['node' => $section, 'label' => false],
                    'allow_add' => true,
                    'allow_delete' => true,
                    'error_bubbling' => false,
                    // Distinct per section: a row's prototype holds the prototypes of its own sections.
                    'prototype_name' => '__'.$section->name.'__',
                    'prototype_data' => $this->composite->data($section, null),
                ]);
            }
            $builder->add($sections);
        }

        // The fields depend on the data: an existing record keeps its password when it is left
        // empty, and is not asked the service fields asked at creation only.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (PreSetDataEvent $event) use ($node, $object): void {
            $data = $event->getData();
            $id = \is_array($data) ? ($data['id'] ?? null) : null;
            $isNew = !\is_string($id) || '' === $id;
            $current = \is_array($data) && \is_array($data['values'] ?? null) ? $data['values'] : [];
            /* @var array<string, mixed> $current */
            $form = $event->getForm();
            if ($form->has('service')) {
                foreach ($node->serviceFields as $field) {
                    if (!$field->isAskedFor($isNew)) {
                        $form->get('service')->remove($field->name);
                    }
                }
                if (0 === $form->get('service')->count()) {
                    $form->remove('service');
                }
            }
            $form->add('values', RecordFieldsType::class, [
                'object' => $object,
                'fields' => $node->fieldNames($object),
                'is_new' => $isNew,
                'current' => $current,
                'required_fields' => $node->required,
                // Inline rows keep their labels off screen: the inputs show them instead.
                'placeholders' => $node->inline,
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Errors stay where the composite record manager puts them (a row, a section).
        $resolver
            ->setDefault('error_bubbling', false)
            ->setRequired('node')
            ->setAllowedTypes('node', FormNodeDto::class);
    }
}
