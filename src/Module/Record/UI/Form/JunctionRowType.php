<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Form;

use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Event\PreSetDataEvent;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One row of a many-to-many field going through a junction object: the hidden id of its
 * junction record ("id", empty for a new row) and the junction's fields ("values"), the
 * target first.
 *
 * Its data is the row as the record manager takes it, flat: ["id" => ..., "tag" => ...,
 * "role" => ...]; a transformer nests the fields under "values" for the controls.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class JunctionRowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var ObjectDefinitionDto $junction */
        $junction = $options['junction'];
        /** @var list<string> $fields */
        $fields = $options['fields'];

        $builder->add('id', HiddenType::class);
        $builder->addModelTransformer(new CallbackTransformer(
            static function (mixed $row): array {
                $row = \is_array($row) ? $row : [];
                $id = $row['id'] ?? null;
                unset($row['id']);

                return ['id' => $id, 'values' => $row];
            },
            static function (mixed $nested): array {
                $nested = \is_array($nested) ? $nested : [];
                $values = \is_array($nested['values'] ?? null) ? $nested['values'] : [];

                return ['id' => $nested['id'] ?? null] + $values;
            },
        ));
        // The controls depend on the row: a relation offers its current record, an existing
        // record keeps its password when it is left empty.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (PreSetDataEvent $event) use ($junction, $fields): void {
            $row = $event->getData();
            $row = \is_array($row) ? $row : [];
            $id = $row['id'] ?? null;
            unset($row['id']);
            $event->getForm()->add('values', RecordFieldsType::class, [
                'object' => $junction,
                'fields' => $fields,
                'is_new' => !\is_string($id) || '' === $id,
                'current' => $row,
                // On one line: the inputs show the labels.
                'placeholders' => true,
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired(['junction', 'fields'])
            ->setAllowedTypes('junction', ObjectDefinitionDto::class)
            ->setAllowedTypes('fields', 'string[]')
            ->setDefaults(['label' => false, 'error_bubbling' => false]);
    }

    public function getBlockPrefix(): string
    {
        return 'junction_row';
    }
}
