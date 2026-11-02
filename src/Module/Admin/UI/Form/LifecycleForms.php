<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Form;

use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * The forms renaming, archiving and restoring objects and fields: built for the pages showing
 * them (AdminController) and for the actions handling them (AdminLifecycleController).
 */
final class LifecycleForms
{
    public function __construct(
        private readonly FormFactoryInterface $forms,
    ) {
    }

    /**
     * @return FormInterface<mixed>
     */
    public function rename(string $action, string $currentName, string $help): FormInterface
    {
        return $this->forms->createNamedBuilder('rename', FormType::class, ['name' => $currentName], ['action' => $action])
            ->add('name', TextType::class, ['label' => 'Machine name', 'help' => $help])
            ->getForm();
    }

    /**
     * A button posting to an action, protected by its CSRF token.
     *
     * @return FormInterface<mixed>
     */
    public function button(string $name, string $action): FormInterface
    {
        return $this->forms->createNamedBuilder($name, FormType::class, null, ['action' => $action])->getForm();
    }
}
