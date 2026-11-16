<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Controller;

use App\Module\Access\Contract\AccessSetupInterface;
use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\WorkspaceRolesInterface;
use App\Module\User\Contract\Dto\Member;
use App\Module\User\Contract\Exception\UserException;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\Workflow\Contract\WorkflowUsageInterface;
use App\Shared\Workspace\Plan;
use App\Shared\Workspace\Plans;
use App\Shared\Workspace\Workspace;
use App\Shared\Workspace\WorkspaceAdministration;
use App\Shared\Workspace\WorkspaceCreated;
use App\Shared\Workspace\Workspaces;
use App\Shared\Workspace\WorkspaceSessions;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The system console (docs/internal/tenant-admin-ui-plan.md, part B): its workspaces,
 * for its system admins. On its own host, in the system session (HostWorkspaceListener); every path
 * needs ROLE_SYSTEM_ADMIN (security.yaml). The counterpart of the workspace:* commands.
 *
 * A workspace's first admin, or another one, is invited: they accept the link on the workspace's host,
 * with their account or a new one, and hold the workspace's super admin role there. The system admin
 * chooses no one's password.
 */
#[Route('/console', name: 'console_')]
final class ConsoleController extends AbstractController
{
    public function __construct(
        private readonly WorkspaceAdministration $administration,
        private readonly Workspaces $workspaces,
        private readonly WorkspaceSessions $sessions,
        private readonly AccessSetupInterface $accessSetup,
        private readonly WorkspaceRolesInterface $roles,
        private readonly UserManagerInterface $users,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $events,
        #[Autowire('%app.workspace.base_host%')]
        private readonly string $baseHost,
        #[Autowire('%app.workspace.default%')]
        private readonly string $default,
        private readonly Plans $plans,
        private readonly WorkflowUsageInterface $usage,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('console/workspaces.html.twig', ['overview' => $this->administration->overview()]);
    }

    #[Route('/workspaces/new', name: 'workspace_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->forms->createNamedBuilder('new_workspace', FormType::class)
            ->add('slug', TextType::class, ['label' => 'console.slug', 'help' => 'console.slug_help'])
            ->add('name', TextType::class, ['label' => 'console.name'])
            ->add('admin_email', EmailType::class, ['label' => 'console.admin_email', 'help' => 'console.admin_email_help', 'required' => false])
            ->getForm()
            ->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $workspace = $this->administration->create(self::text($form, 'slug'), self::text($form, 'name'));
            } catch (\InvalidArgumentException $e) {
                $form->get('slug')->addError(new FormError($e->getMessage()));
            }
            if (isset($workspace)) {
                // Its permissions and super admin role, as access:sync makes them.
                $this->sessions->within($workspace, fn () => $this->accessSetup->syncAll());
                $this->log('created workspace', $workspace);
                $email = self::text($form, 'admin_email');
                if ('' !== $email) {
                    $this->inviteAdmin($workspace, $email, $request);
                }
                // The system admin's workflows run on it, once the response is sent.
                $this->events->dispatch(new WorkspaceCreated($workspace));
                $this->addFlash('success', $this->translator->trans('console.created', ['%slug%' => $workspace->slug]));

                return $this->redirectToRoute('console_workspace_show', ['slug' => $workspace->slug], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('console/workspace_new.html.twig', ['form' => $form->createView()], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/workspaces/{slug}', name: 'workspace_show', methods: ['GET'])]
    public function show(string $slug, Request $request): Response
    {
        $workspace = $this->workspaceOr404($slug);
        $members = $this->users->members($workspace->id);
        // Who holds the super admin role there: read in the workspace's session, its RBAC tables.
        $admins = $this->sessions->within($workspace, fn (): array => array_values(array_filter(
            array_map(static fn (Member $member): string => $member->email, $members),
            fn (string $email): bool => \in_array(Permissions::SUPER_ADMIN_ROLE, $this->roles->rolesOf($email), true),
        )));
        $rows = [];
        foreach ($members as $member) {
            $rows[] = [
                'member' => $member,
                'admin' => \in_array($member->email, $admins, true),
                'admin_form' => $this->actionForm('admin_'.self::key($member), 'console_member_admin', $workspace, ['key' => self::key($member)])->createView(),
                'remove_form' => $this->actionForm('remove_'.self::key($member), 'console_member_remove', $workspace, ['key' => self::key($member)])->createView(),
            ];
        }

        return $this->render('console/workspace_show.html.twig', [
            'workspace' => $workspace,
            'url' => $this->workspaceUrl($workspace, '/', $request),
            'is_default' => $workspace->slug === $this->default,
            'rows' => $rows,
            'status_form' => $this->actionForm('status', 'console_workspace_status', $workspace)->createView(),
            'invite_form' => $this->inviteForm($workspace)->createView(),
            'delete_form' => $this->deleteForm($workspace)->createView(),
            'plan' => $this->plans->of($workspace),
            'plan_form' => $this->planForm($workspace)->createView(),
            // Its workflow steps this month: read in its session, its rows.
            'usage' => $this->sessions->within($workspace, $this->usage->thisMonth(...)),
        ]);
    }

    #[Route('/workspaces/{slug}/plan', name: 'workspace_plan', methods: ['POST'])]
    public function plan(string $slug, Request $request): Response
    {
        $workspace = $this->workspaceOr404($slug);
        $form = $this->planForm($workspace)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $plan = self::text($form, 'plan');
            $this->administration->assignPlan($workspace, $plan);
            $this->log('put on the plan '.$plan, $workspace);
            $this->addFlash('success', $this->translator->trans('console.plan_changed', ['%slug%' => $workspace->slug, '%plan%' => $this->plans->get($plan)->label ?? $plan]));
        }

        return $this->redirectToRoute('console_workspace_show', ['slug' => $workspace->slug], Response::HTTP_SEE_OTHER);
    }

    #[Route('/workspaces/{slug}/status', name: 'workspace_status', methods: ['POST'])]
    public function status(string $slug, Request $request): Response
    {
        $workspace = $this->workspaceOr404($slug);
        if ($this->isValidSubmission($this->actionForm('status', 'console_workspace_status', $workspace), $request)) {
            $workspace->active ? $this->administration->suspend($workspace) : $this->administration->resume($workspace);
            $this->log($workspace->active ? 'suspended workspace' : 'resumed workspace', $workspace);
            $this->addFlash('success', $this->translator->trans($workspace->active ? 'console.suspended' : 'console.resumed', ['%slug%' => $workspace->slug]));
        }

        return $this->redirectToRoute('console_workspace_show', ['slug' => $workspace->slug], Response::HTTP_SEE_OTHER);
    }

    #[Route('/workspaces/{slug}/delete', name: 'workspace_delete', methods: ['POST'])]
    public function delete(string $slug, Request $request): Response
    {
        $workspace = $this->workspaceOr404($slug);
        if ($workspace->slug === $this->default) {
            throw $this->createAccessDeniedException('The default workspace can\'t be deleted.');
        }
        $form = $this->deleteForm($workspace)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // Irreversible: confirmed by typing the slug back, as workspace:delete.
            if (self::text($form, 'confirm') !== $workspace->slug) {
                $this->addFlash('error', $this->translator->trans('console.not_confirmed'));

                return $this->redirectToRoute('console_workspace_show', ['slug' => $workspace->slug], Response::HTTP_SEE_OTHER);
            }
            $this->administration->delete($workspace);
            $this->log('deleted workspace', $workspace);
            $this->addFlash('success', $this->translator->trans('console.deleted', ['%slug%' => $workspace->slug]));
        }

        return $this->redirectToRoute('console_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/workspaces/{slug}/invite', name: 'workspace_invite', methods: ['POST'])]
    public function invite(string $slug, Request $request): Response
    {
        $workspace = $this->workspaceOr404($slug);
        $form = $this->inviteForm($workspace)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->inviteAdmin($workspace, self::text($form, 'email'), $request);
        }

        return $this->redirectToRoute('console_workspace_show', ['slug' => $workspace->slug], Response::HTTP_SEE_OTHER);
    }

    #[Route('/workspaces/{slug}/members/{key}/admin', name: 'member_admin', requirements: ['key' => '[0-9a-f]{12}'], methods: ['POST'])]
    public function toggleAdmin(string $slug, string $key, Request $request): Response
    {
        $workspace = $this->workspaceOr404($slug);
        $member = $this->memberOr404($workspace, $key);
        if ($this->isValidSubmission($this->actionForm('admin_'.$key, 'console_member_admin', $workspace, ['key' => $key]), $request)) {
            $made = $this->sessions->within($workspace, function () use ($member): bool {
                // The role exists even where access:sync never ran.
                $this->accessSetup->syncAll();
                $roles = $this->roles->rolesOf($member->email);
                $admin = \in_array(Permissions::SUPER_ADMIN_ROLE, $roles, true);
                $this->roles->setRoles($member->email, $admin
                    ? array_values(array_diff($roles, [Permissions::SUPER_ADMIN_ROLE]))
                    : [...$roles, Permissions::SUPER_ADMIN_ROLE]);

                return !$admin;
            });
            $this->log($made ? 'gave the super admin role' : 'took the super admin role', $workspace, $member->email);
        }

        return $this->redirectToRoute('console_workspace_show', ['slug' => $workspace->slug], Response::HTTP_SEE_OTHER);
    }

    #[Route('/workspaces/{slug}/members/{key}/remove', name: 'member_remove', requirements: ['key' => '[0-9a-f]{12}'], methods: ['POST'])]
    public function removeMember(string $slug, string $key, Request $request): Response
    {
        $workspace = $this->workspaceOr404($slug);
        $member = $this->memberOr404($workspace, $key);
        if ($this->isValidSubmission($this->actionForm('remove_'.$key, 'console_member_remove', $workspace, ['key' => $key]), $request)) {
            $this->users->removeFromWorkspace($member->email, $workspace->id);
            $this->log('removed member', $workspace, $member->email);
            $this->addFlash('success', $this->translator->trans('admin.members.removed', ['%name%' => $member->displayName]));
        }

        return $this->redirectToRoute('console_workspace_show', ['slug' => $workspace->slug], Response::HTTP_SEE_OTHER);
    }

    /**
     * An admin of the workspace, invited: they hold its super admin role once they accept.
     */
    private function inviteAdmin(Workspace $workspace, string $email, Request $request): void
    {
        $email = $this->users->canonicalEmail($email) ?? mb_strtolower(trim($email));
        try {
            // The system admin is above every admin: their invitation replaces a pending one.
            $token = $this->users->invite($email, $workspace->id, $this->systemAdmin(), true, true);
        } catch (UserException $e) {
            $this->addFlash('error', $e->getMessage());

            return;
        }
        // The role is keyed by the email: theirs as soon as they accept, with an account or a new one.
        $this->sessions->within($workspace, function () use ($email): void {
            // The role exists even where access:sync never ran.
            $this->accessSetup->syncAll();
            $this->roles->setRoles($email, array_values(array_unique([...$this->roles->rolesOf($email), Permissions::SUPER_ADMIN_ROLE])));
        });
        $this->log('invited an admin', $workspace, $email);
        $this->addFlash('success', $this->translator->trans('admin.members.invited', ['%email%' => $email]));
        $this->addFlash('invitation', $this->workspaceUrl($workspace, '/invitation/'.$token, $request));
    }

    /**
     * The workspace's own address: <slug>.<base host>, the default workspace's the base host itself,
     * with the console's scheme and port.
     */
    private function workspaceUrl(Workspace $workspace, string $path, Request $request): string
    {
        $port = $request->getPort();
        $port = null === $port || \in_array($port, [80, 443], true) ? '' : ':'.$port;
        $host = $workspace->slug === $this->default ? $this->baseHost : $workspace->slug.'.'.$this->baseHost;

        return \sprintf('%s://%s%s%s', $request->getScheme(), $host, $port, $path);
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return FormInterface<mixed>
     */
    private function actionForm(string $name, string $route, Workspace $workspace, array $parameters = []): FormInterface
    {
        return $this->forms->createNamedBuilder($name, FormType::class, null, [
            'action' => $this->generateUrl($route, ['slug' => $workspace->slug, ...$parameters]),
        ])->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function inviteForm(Workspace $workspace): FormInterface
    {
        return $this->forms->createNamedBuilder('invite_admin', FormType::class, null, [
            'action' => $this->generateUrl('console_workspace_invite', ['slug' => $workspace->slug]),
        ])->add('email', EmailType::class, ['label' => 'admin.members.email'])->getForm();
    }

    /**
     * The plans to choose from, the workspace's own selected.
     *
     * @return FormInterface<mixed>
     */
    private function planForm(Workspace $workspace): FormInterface
    {
        return $this->forms->createNamedBuilder('workspace_plan', FormType::class, ['plan' => $this->plans->of($workspace)->name], [
            'action' => $this->generateUrl('console_workspace_plan', ['slug' => $workspace->slug]),
        ])->add('plan', ChoiceType::class, [
            'label' => 'console.plan',
            'choices' => array_combine(
                array_map(static fn (Plan $plan): string => \sprintf('%s (%s)', $plan->label, null === $plan->workflowStepsPerMonth ? '∞' : number_format($plan->workflowStepsPerMonth, 0, '', ' ')), $this->plans->all()),
                array_keys($this->plans->all()),
            ),
            'choice_translation_domain' => false,
        ])->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function deleteForm(Workspace $workspace): FormInterface
    {
        return $this->forms->createNamedBuilder('delete_workspace', FormType::class, null, [
            'action' => $this->generateUrl('console_workspace_delete', ['slug' => $workspace->slug]),
        ])->add('confirm', TextType::class, ['label' => 'console.confirm', 'help' => 'console.confirm_help'])->getForm();
    }

    private function workspaceOr404(string $slug): Workspace
    {
        return $this->workspaces->bySlug($slug) ?? throw $this->createNotFoundException(\sprintf('No workspace "%s".', $slug));
    }

    private function memberOr404(Workspace $workspace, string $key): Member
    {
        foreach ($this->users->members($workspace->id) as $member) {
            if (self::key($member) === $key) {
                return $member;
            }
        }

        throw $this->createNotFoundException('No such member.');
    }

    private function systemAdmin(): string
    {
        return $this->getUser()?->getUserIdentifier() ?? '';
    }

    /**
     * Every action of the console, logged: who, what, which workspace.
     */
    private function log(string $action, Workspace $workspace, ?string $email = null): void
    {
        $this->logger->notice('System admin {admin} {action} {workspace}{email}', [
            'admin' => $this->systemAdmin(),
            'action' => $action,
            'workspace' => $workspace->slug,
            'email' => null === $email ? '' : ' ('.$email.')',
        ]);
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

    /**
     * Submitted, and valid: its CSRF token checked. An action of the console runs on nothing less.
     *
     * @param FormInterface<mixed> $form
     */
    private function isValidSubmission(FormInterface $form, Request $request): bool
    {
        $form->handleRequest($request);

        return $form->isSubmitted() && $form->isValid();
    }
}
