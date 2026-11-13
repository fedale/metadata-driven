<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Controller;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\TenantRolesInterface;
use App\Module\User\Contract\Dto\Member;
use App\Module\User\Contract\Exception\UserException;
use App\Module\User\Contract\UserManagerInterface;
use App\Shared\Tenant\CurrentTenant;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The members of the current tenant: who may sign in to it, with which of its roles
 * (docs/internal/tenant-admin-ui-plan.md, parts A and A2). New members come by invitation.
 *
 * A member is named in the forms by a short hash of their email, not the email: no address in
 * a URL, and an id of its own for each row's forms.
 *
 * No way to more power: an admin gives and takes only the roles they could hold themselves
 * (AccessCheckerInterface::canGrant(): they hold the role, or every permission it gives; the
 * super admin holds them all), and leaves alone a member holding a role they don't: their roles and
 * membership don't change, and once removed (their roles stay) they aren't added back. Else the
 * right to manage members would be the right to make anyone, an account of one's own included,
 * super admin, or to take from those above.
 */
#[Route('/admin/members', name: 'admin_member_')]
final class AdminMemberController extends AbstractController
{
    public function __construct(
        private readonly UserManagerInterface $users,
        private readonly TenantRolesInterface $roles,
        private readonly AccessCheckerInterface $access,
        private readonly CurrentTenant $tenant,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAllowed();

        return $this->page($this->addForm());
    }

    #[Route('/add', name: 'add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $this->denyUnlessAllowed();
        $form = $this->addForm()->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->page($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $email = self::text($form, 'email');
        // The same answer whether the email has an account or not: an invitation, accepted by
        // whoever the email belongs to (tenant-admin-ui-plan.md, part A2). If it names an account
        // holding assignments here you don't hold (a former member above you), the invitation
        // takes them away on acceptance: nothing told, no escalation.
        // The assignments are keyed by the email, account or not: one given a role ahead of its
        // account (the console invites a tenant's admin so) is weighed all the same.
        $existing = $this->users->canonicalEmail($email);
        $keepAssignments = $this->outranks($existing ?? mb_strtolower(trim($email)));
        try {
            $token = $this->users->invite($email, $this->tenant->id(), $this->yourEmail(), $keepAssignments);
            $this->addFlash('success', $this->translator->trans('admin.members.invited', ['%email%' => $email]));
            $this->addFlash('invitation', $this->generateUrl('invitation_show', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL));
        } catch (UserException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_member_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/invitations/{id}/revoke', name: 'invitation_revoke', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function revokeInvitation(string $id, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $form = $this->revokeForm($id)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $this->users->revokeInvitation($this->tenant->id(), $id)) {
            $this->addFlash('success', $this->translator->trans('admin.members.invitation_revoked'));
        }

        return $this->redirectToRoute('admin_member_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{key}/roles', name: 'roles', requirements: ['key' => '[0-9a-f]{12}'], methods: ['POST'])]
    public function roles(string $key, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $member = $this->memberOr404($key);
        $this->denyIfYourself($member);
        if (!$this->outranks($member->email)) {
            throw $this->createAccessDeniedException('This member holds a role you don\'t: another admin changes their roles.');
        }
        // Every role a choice here, so that a forged one is refused below rather than dropped.
        $form = $this->rolesForm($member, array_keys($this->roles->roles()))->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $grantable = $this->grantable();
            /** @var list<string> $submitted */
            $submitted = array_values(array_filter((array) $form->get('roles')->getData(), \is_string(...)));
            if ([] !== array_diff($submitted, $grantable)) {
                throw $this->createAccessDeniedException('You may give only the roles you hold.');
            }
            // You outrank them: every role they hold is one you may take.
            $this->roles->setRoles($member->email, $submitted);
            $this->addFlash('success', $this->translator->trans('admin.members.roles_saved', ['%name%' => $member->displayName]));
        }

        return $this->redirectToRoute('admin_member_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{key}/remove', name: 'remove', requirements: ['key' => '[0-9a-f]{12}'], methods: ['POST'])]
    public function remove(string $key, Request $request): Response
    {
        $this->denyUnlessAllowed();
        $member = $this->memberOr404($key);
        $this->denyIfYourself($member);
        if (!$this->outranks($member->email)) {
            throw $this->createAccessDeniedException('This member holds a role you don\'t: another admin removes them.');
        }
        $form = $this->removeForm($member)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->users->removeFromTenant($member->email, $this->tenant->id());
            $this->addFlash('success', $this->translator->trans('admin.members.removed', ['%name%' => $member->displayName]));
        }

        return $this->redirectToRoute('admin_member_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param FormInterface<mixed> $add
     */
    private function page(FormInterface $add, int $status = Response::HTTP_OK): Response
    {
        $you = $this->yourEmail();
        $grantable = $this->grantable();
        $rows = [];
        foreach ($this->users->members($this->tenant->id()) as $member) {
            $yourself = $member->email === $you;
            $roles = $this->roles->rolesOf($member->email);
            $rows[] = [
                'member' => $member,
                'roles' => $roles,
                'yourself' => $yourself,
                // Your own row is another admin's to change (no lockout), and so is the row of
                // a member holding a role you don't (no escalation, nor taking from above).
                'roles_form' => $yourself || [] === $grantable || !$this->outranks($member->email) ? null : $this->rolesForm($member, $grantable)->createView(),
                'remove_form' => $yourself || !$this->outranks($member->email) ? null : $this->removeForm($member)->createView(),
            ];
        }

        $invitations = [];
        foreach ($this->users->invitations($this->tenant->id()) as $invitation) {
            $invitations[] = ['invitation' => $invitation, 'revoke_form' => $this->revokeForm($invitation->id)->createView()];
        }

        return $this->render('admin/members.html.twig', [
            'rows' => $rows,
            'invitations' => $invitations,
            'add_form' => $add->createView(),
        ], new Response(status: $status));
    }

    /**
     * @return FormInterface<mixed>
     */
    private function addForm(): FormInterface
    {
        return $this->forms->createNamedBuilder('add_member', FormType::class, null, [
            'action' => $this->generateUrl('admin_member_add'),
        ])
            ->add('email', EmailType::class, ['label' => 'admin.members.email', 'help' => 'admin.members.email_help'])
            ->getForm();
    }

    /**
     * @param list<string> $roles the choices
     *
     * @return FormInterface<mixed>
     */
    private function rolesForm(Member $member, array $roles): FormInterface
    {
        $key = self::key($member);

        return $this->forms->createNamedBuilder('roles_'.$key, FormType::class, ['roles' => array_values(array_intersect($this->roles->rolesOf($member->email), $roles))], [
            'action' => $this->generateUrl('admin_member_roles', ['key' => $key]),
        ])
            ->add('roles', ChoiceType::class, [
                'label' => 'admin.members.roles',
                'choices' => array_combine($roles, $roles),
                'choice_translation_domain' => false,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function removeForm(Member $member): FormInterface
    {
        $key = self::key($member);

        return $this->forms->createNamedBuilder('remove_'.$key, FormType::class, null, [
            'action' => $this->generateUrl('admin_member_remove', ['key' => $key]),
        ])->getForm();
    }

    /**
     * The roles of the tenant you hold, so may give and take.
     *
     * @return list<string>
     */
    private function grantable(): array
    {
        return array_values(array_filter(array_keys($this->roles->roles()), $this->access->canGrant(...)));
    }

    /**
     * Whether you hold everything assigned to the user in this tenant, roles and permissions
     * assigned directly alike: then you may change their roles, remove them, or add them back.
     *
     * @param string $email the account's canonical email (UserManagerInterface::canonicalEmail())
     */
    private function outranks(string $email): bool
    {
        foreach ($this->roles->assignmentsOf($email) as $item) {
            if (!$this->access->canGrant($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return FormInterface<mixed>
     */
    private function revokeForm(string $id): FormInterface
    {
        return $this->forms->createNamedBuilder('revoke_'.substr(str_replace('-', '', $id), -12), FormType::class, null, [
            'action' => $this->generateUrl('admin_member_invitation_revoke', ['id' => $id]),
        ])->getForm();
    }

    private function memberOr404(string $key): Member
    {
        foreach ($this->users->members($this->tenant->id()) as $member) {
            if (self::key($member) === $key) {
                return $member;
            }
        }

        throw $this->createNotFoundException('No such member.');
    }

    private function denyIfYourself(Member $member): void
    {
        if ($member->email === $this->yourEmail()) {
            throw $this->createAccessDeniedException('Your own roles and membership are another admin\'s to change.');
        }
    }

    private function denyUnlessAllowed(): void
    {
        if (!$this->access->canManageMembers()) {
            throw $this->createAccessDeniedException('No permission to manage the members.');
        }
    }

    private function yourEmail(): string
    {
        return $this->getUser()?->getUserIdentifier() ?? '';
    }

    private static function key(Member $member): string
    {
        return substr(hash('sha256', $member->email), 0, 12);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private static function text(FormInterface $form, string $child): string
    {
        $value = $form->get($child)->getData();

        return \is_string($value) ? trim($value) : '';
    }
}
