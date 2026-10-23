<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Form;

use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Some fields of a record, as a form of their own: the "values" of a composite form node.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class RecordFieldsType extends AbstractType
{
    public function __construct(
        private readonly RecordFormFactory $forms,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var ObjectDefinitionDto $object */
        $object = $options['object'];
        /** @var list<string> $fields */
        $fields = $options['fields'];
        /** @var array<string, mixed> $current */
        $current = $options['current'];
        /** @var list<string> $required */
        $required = $options['required_fields'];
        $this->forms->addControls($builder, $object, $fields, true === $options['is_new'], $current, $required, true === $options['placeholders']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired(['object', 'fields'])
            ->setAllowedTypes('object', ObjectDefinitionDto::class)
            ->setAllowedTypes('fields', 'string[]')
            ->setDefaults(['is_new' => true, 'current' => [], 'required_fields' => [], 'placeholders' => false, 'label' => false, 'error_bubbling' => false])
            ->setAllowedTypes('required_fields', 'string[]')
            ->setAllowedTypes('placeholders', 'bool')
            ->setAllowedTypes('is_new', 'bool')
            ->setAllowedTypes('current', 'array');
    }
}
