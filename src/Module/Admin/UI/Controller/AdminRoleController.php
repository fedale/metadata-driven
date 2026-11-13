<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\TenantRolesInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The roles of the tenant: which permissions each gives (docs/internal/tenant-admin-ui-plan.md,
 * part D). Who holds them is the members page's.
 *
 * No way to more power, nor to take from those above:
 * - a role gets only permissions you hold;
 * - you edit a role only when you hold every permission it gives, and every member holding it
 *   holds nothing you couldn't give (AccessCheckerInterface::canGrant()); through the hierarchy
 *   too: the roles containing it count as it, and so do their holders;
 * - you delete a role only on top of that when no member holds it and no role contains it;
 * - the super admin role is never edited here, and the hierarchy (a role within a role) stays the
 *   console's (rbac:child:add): shown, left alone.
 */
#[Route('/admin/roles', name: 'admin_role_')]
final class AdminRoleController extends AbstractController
{
    private const string ROLE = 'ROLE_[A-Z0-9_]{1,59}';

    public function __construct(
        private readonly TenantRolesInterface $roles,
        private readonly AccessCheckerInterface $access,
        #[Target('localizedMetadata')]
        private readonly MetadataRegistryInterface $metadata,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessAllowed();
        $new = $this->forms->createNamedBuilder('new_role', FormType::class)
            ->add('name', TextType::class, ['label' => 'admin.roles.name', 'help' => 'admin.roles.name_help'])
            ->add('description', TextType::class, ['label' => 'admin.roles.description', 'required' => false])
            ->add('permissions', ChoiceType::class, $this->permissionChoices())
            ->getForm()
            ->handleRequest($request);
        if ($new->isSubmitted() && $new->isValid()) {
            $name = self::text($new, 'name');
            try {
                $this->roles->createRole($name, self::text($new, 'description') ?: null, self::list($new, 'permissions'));
                $this->addFlash('success', $this->translator->trans('admin.roles.created', ['%name%' => $name]));

                return $this->redirectToRoute('admin_role_index', [], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException $e) {
                $new->get('name')->addError(new FormError($e->getMessage()));
            }
        }

        $rows = [];
        foreach ($this->roles->roles() as $role => $description) {
            $editable = $this->isEditable($role);
            $rows[] = [
                'name' => $role,
                'description' => $description,
                'permissions' => array_map($this->permissionLabel(...), $this->roles->permissionsOf($role)),
                'roles' => $this->roles->childRolesOf($role),
                'holders' => \count($this->holdersOf($role)),
                'editable' => $editable,
                'delete_form' => $editable && $this->isDeletable($role) ? $this->deleteForm($role)->createView() : null,
            ];
        }

        return $this->render('admin/roles.html.twig', [
            'rows' => $rows,
            'new_form' => $new->createView(),
            // A form submitted and still here (a successful one redirected) is one to correct.
        ], new Response(status: $new->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{role}', name: 'edit', requirements: ['role' => self::ROLE], methods: ['GET', 'POST'])]
    public function edit(string $role, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $this->roleOr404($role);
        $this->denyUnlessEditable($role);
        $form = $this->forms->createNamedBuilder('role', FormType::class, ['permissions' => $this->roles->permissionsOf($role)])
            ->add('permissions', ChoiceType::class, $this->permissionChoices())
            ->getForm()
            ->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // Only permissions you hold are choices: anything else is no valid submission.
            $this->roles->setPermissions($role, self::list($form, 'permissions'));
            $this->addFlash('success', $this->translator->trans('admin.roles.saved', ['%name%' => $role]));

            return $this->redirectToRoute('admin_role_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/role_edit.html.twig', [
            'role' => $role,
            'roles' => $this->roles->childRolesOf($role),
            'form' => $form->createView(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{role}/delete', name: 'delete', requirements: ['role' => self::ROLE], methods: ['POST'])]
    public function delete(string $role, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $this->roleOr404($role);
        $this->denyUnlessEditable($role);
        if (!$this->isDeletable($role)) {
            throw $this->createAccessDeniedException('Members hold this role, or another role contains it: neither changes from here.');
        }
        $form = $this->deleteForm($role)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->roles->deleteRole($role);
            $this->addFlash('success', $this->translator->trans('admin.roles.deleted', ['%name%' => $role]));
        }

        return $this->redirectToRoute('admin_role_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * The permissions you hold, the only ones a role gets here, grouped by object.
     *
     * @return array<string, mixed> the options of a ChoiceType
     */
    private function permissionChoices(): array
    {
        $choices = [];
        foreach (array_keys($this->roles->permissions()) as $permission) {
            if ($this->access->holds($permission)) {
                $choices[$this->permissionGroup($permission)][$this->permissionLabel($permission)] = $permission;
            }
        }
        ksort($choices);

        return [
            'label' => 'admin.roles.permissions',
            'choices' => $choices,
            'choice_translation_domain' => false,
            'multiple' => true,
            'expanded' => true,
            'required' => false,
        ];
    }

    private function permissionGroup(string $permission): string
    {
        $object = self::objectOf($permission);

        return null === $object
            ? $this->translator->trans('admin.roles.administration')
            : ($this->metadata->hasObject($object) ? $this->metadata->getObject($object)->labelPlural : $object);
    }

    private function permissionLabel(string $permission): string
    {
        $object = self::objectOf($permission);
        if (null !== $object) {
            $action = substr($permission, \strlen('record.'.$object.'.'));
            $text = $this->translator->trans('admin.roles.action.'.$action);

            return $this->metadata->hasObject($object) ? $this->metadata->getObject($object)->labelPlural.': '.$text : $object.': '.$text;
        }
        $key = 'admin.roles.permission.'.str_replace('.', '_', $permission);
        $text = $this->translator->trans($key);

        return $text === $key ? $permission : $text;
    }

    /**
     * The object of a record permission (record.<object>.<action>), null for the others.
     */
    private static function objectOf(string $permission): ?string
    {
        return 1 === preg_match('/^record\.([a-z][a-z0-9_]*)\.(read|create|update|delete)$/', $permission, $match) ? $match[1] : null;
    }

    /**
     * Who holds the role, directly or through a role containing it: members or not, from the
     * assignments themselves (a member removed keeps theirs; an email may get one ahead of its
     * account).
     *
     * @return list<string> emails
     */
    private function holdersOf(string $role): array
    {
        $emails = [];
        foreach ([$role, ...$this->roles->rolesContaining($role)] as $holding) {
            $emails = [...$emails, ...$this->roles->assigneesOf($holding)];
        }

        return array_values(array_unique($emails));
    }

    /**
     * Editing a role changes every role containing it: each must be one you could edit, and
     * whoever holds any of them must hold nothing you couldn't give.
     */
    private function isEditable(string $role): bool
    {
        foreach ([$role, ...$this->roles->rolesContaining($role)] as $changed) {
            if (Permissions::SUPER_ADMIN_ROLE === $changed) {
                return false;
            }
            foreach ($this->roles->permissionsOf($changed) as $permission) {
                if (!$this->access->holds($permission)) {
                    return false;
                }
            }
        }
        // Not to take from those above: whoever holds it holds nothing you couldn't give.
        foreach ($this->holdersOf($role) as $email) {
            foreach ($this->roles->assignmentsOf($email) as $item) {
                if (!$this->access->canGrant($item)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * No one holds it, and no role contains it: deleting it would change that role.
     */
    private function isDeletable(string $role): bool
    {
        return [] === $this->holdersOf($role) && [] === $this->roles->rolesContaining($role);
    }

    private function denyUnlessEditable(string $role): void
    {
        if (!$this->isEditable($role)) {
            throw $this->createAccessDeniedException('This role gives, or is held by someone holding, more than you do: another admin changes it.');
        }
    }

    private function roleOr404(string $role): void
    {
        if (!\array_key_exists($role, $this->roles->roles())) {
            throw $this->createNotFoundException(\sprintf('No role "%s".', $role));
        }
    }

    private function denyUnlessAllowed(): void
    {
        if (!$this->access->canManageRoles()) {
            throw $this->createAccessDeniedException('No permission to manage the roles.');
        }
    }

    /**
     * @return FormInterface<mixed>
     */
    private function deleteForm(string $role): FormInterface
    {
        return $this->forms->createNamedBuilder('delete_'.strtolower($role), FormType::class, null, [
            'action' => $this->generateUrl('admin_role_delete', ['role' => $role]),
        ])->getForm();
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private static function text(FormInterface $form, string $child): string
    {
        $value = $form->get($child)->getData();

        return \is_string($value) ? trim($value) : '';
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return list<string>
     */
    private static function list(FormInterface $form, string $child): array
    {
        return array_values(array_filter((array) $form->get($child)->getData(), \is_string(...)));
    }
}
