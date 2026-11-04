<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The rows of a many-to-many field going through a junction object (JunctionRowType each),
 * drawn as the repeatable rows of fedale/gridview-bundle (its gridview-collection controller):
 * see the junction_rows blocks of templates/form/record_fields.html.twig.
 *
 * "min" and "max" (0: no limit) only guide the buttons; the record manager checks both.
 *
 * @extends AbstractType<array<int|string, array<string, mixed>>>
 */
final class JunctionRowsType extends AbstractType
{
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['prototype_name'] = $options['prototype_name'];
        $view->vars['collection_min'] = $options['min'];
        $view->vars['collection_max'] = $options['max'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'entry_type' => JunctionRowType::class,
                'allow_add' => true,
                'allow_delete' => true,
                // A row added and left blank is not a link.
                'delete_empty' => static fn (mixed $row): bool => self::isBlank($row),
                'error_bubbling' => false,
                'min' => 0,
                'max' => 0,
            ])
            ->setAllowedTypes('min', 'int')
            ->setAllowedTypes('max', 'int');
    }

    public function getParent(): string
    {
        return CollectionType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'junction_rows';
    }

    /**
     * A new row whose every field is empty.
     */
    private static function isBlank(mixed $row): bool
    {
        if (!\is_array($row)) {
            return null === $row;
        }
        if (\is_string($row['id'] ?? null) && '' !== $row['id']) {
            return false;
        }
        foreach ($row as $key => $value) {
            if ('id' !== $key && null !== $value && '' !== $value && [] !== $value && false !== $value) {
                return false;
            }
        }

        return true;
    }
}
