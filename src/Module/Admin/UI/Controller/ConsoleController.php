<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Controller;

use App\Module\Access\Contract\AccessSetupInterface;
use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\TenantRolesInterface;
use App\Module\User\Contract\Dto\Member;
use App\Module\User\Contract\Exception\UserException;
use App\Module\User\Contract\UserManagerInterface;
use App\Shared\Tenant\Tenant;
use App\Shared\Tenant\TenantAdministration;
use App\Shared\Tenant\Tenants;
use App\Shared\Tenant\TenantSessions;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
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
 * The console of the installation (docs/internal/tenant-admin-ui-plan.md, part B): its tenants,
 * for its operators. On its own host, in the landlord's session (HostTenantListener); every path
 * needs ROLE_OPERATOR (security.yaml). The counterpart of the tenant:* commands.
 *
 * A tenant's first admin, or another one, is invited: they accept the link on the tenant's host,
 * with their account or a new one, and hold the tenant's super admin role there. The operator
 * chooses no one's password.
 */
#[Route('/console', name: 'console_')]
final class ConsoleController extends AbstractController
{
    public function __construct(
        private readonly TenantAdministration $administration,
        private readonly Tenants $tenants,
        private readonly TenantSessions $sessions,
        private readonly AccessSetupInterface $accessSetup,
        private readonly TenantRolesInterface $roles,
        private readonly UserManagerInterface $users,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.tenant.base_host%')]
        private readonly string $baseHost,
        #[Autowire('%app.tenant.default%')]
        private readonly string $default,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('console/tenants.html.twig', ['overview' => $this->administration->overview()]);
    }

    #[Route('/tenants/new', name: 'tenant_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->forms->createNamedBuilder('new_tenant', FormType::class)
            ->add('slug', TextType::class, ['label' => 'console.slug', 'help' => 'console.slug_help'])
            ->add('name', TextType::class, ['label' => 'console.name'])
            ->add('admin_email', EmailType::class, ['label' => 'console.admin_email', 'help' => 'console.admin_email_help', 'required' => false])
            ->getForm()
            ->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $tenant = $this->administration->create(self::text($form, 'slug'), self::text($form, 'name'));
            } catch (\InvalidArgumentException $e) {
                $form->get('slug')->addError(new FormError($e->getMessage()));
            }
            if (isset($tenant)) {
                // Its permissions and super admin role, as access:sync makes them.
                $this->sessions->within($tenant, fn () => $this->accessSetup->syncAll());
                $this->log('created tenant', $tenant);
                $email = self::text($form, 'admin_email');
                if ('' !== $email) {
                    $this->inviteAdmin($tenant, $email, $request);
                }
                $this->addFlash('success', $this->translator->trans('console.created', ['%slug%' => $tenant->slug]));

                return $this->redirectToRoute('console_tenant_show', ['slug' => $tenant->slug], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('console/tenant_new.html.twig', ['form' => $form->createView()], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/tenants/{slug}', name: 'tenant_show', methods: ['GET'])]
    public function show(string $slug, Request $request): Response
    {
        $tenant = $this->tenantOr404($slug);
        $members = $this->users->members($tenant->id);
        // Who holds the super admin role there: read in the tenant's session, its RBAC tables.
        $admins = $this->sessions->within($tenant, fn (): array => array_values(array_filter(
            array_map(static fn (Member $member): string => $member->email, $members),
            fn (string $email): bool => \in_array(Permissions::SUPER_ADMIN_ROLE, $this->roles->rolesOf($email), true),
        )));
        $rows = [];
        foreach ($members as $member) {
            $rows[] = [
                'member' => $member,
                'admin' => \in_array($member->email, $admins, true),
                'admin_form' => $this->actionForm('admin_'.self::key($member), 'console_member_admin', $tenant, ['key' => self::key($member)])->createView(),
                'remove_form' => $this->actionForm('remove_'.self::key($member), 'console_member_remove', $tenant, ['key' => self::key($member)])->createView(),
            ];
        }

        return $this->render('console/tenant_show.html.twig', [
            'tenant' => $tenant,
            'url' => $this->tenantUrl($tenant, '/', $request),
            'is_default' => $tenant->slug === $this->default,
            'rows' => $rows,
            'status_form' => $this->actionForm('status', 'console_tenant_status', $tenant)->createView(),
            'invite_form' => $this->inviteForm($tenant)->createView(),
            'delete_form' => $this->deleteForm($tenant)->createView(),
        ]);
    }

    #[Route('/tenants/{slug}/status', name: 'tenant_status', methods: ['POST'])]
    public function status(string $slug, Request $request): Response
    {
        $tenant = $this->tenantOr404($slug);
        if ($this->actionForm('status', 'console_tenant_status', $tenant)->handleRequest($request)->isSubmitted()) {
            $tenant->active ? $this->administration->suspend($tenant) : $this->administration->resume($tenant);
            $this->log($tenant->active ? 'suspended tenant' : 'resumed tenant', $tenant);
            $this->addFlash('success', $this->translator->trans($tenant->active ? 'console.suspended' : 'console.resumed', ['%slug%' => $tenant->slug]));
        }

        return $this->redirectToRoute('console_tenant_show', ['slug' => $tenant->slug], Response::HTTP_SEE_OTHER);
    }

    #[Route('/tenants/{slug}/delete', name: 'tenant_delete', methods: ['POST'])]
    public function delete(string $slug, Request $request): Response
    {
        $tenant = $this->tenantOr404($slug);
        if ($tenant->slug === $this->default) {
            throw $this->createAccessDeniedException('The default tenant can\'t be deleted.');
        }
        $form = $this->deleteForm($tenant)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // Irreversible: confirmed by typing the slug back, as tenant:delete.
            if (self::text($form, 'confirm') !== $tenant->slug) {
                $this->addFlash('error', $this->translator->trans('console.not_confirmed'));

                return $this->redirectToRoute('console_tenant_show', ['slug' => $tenant->slug], Response::HTTP_SEE_OTHER);
            }
            $this->administration->delete($tenant);
            $this->log('deleted tenant', $tenant);
            $this->addFlash('success', $this->translator->trans('console.deleted', ['%slug%' => $tenant->slug]));
        }

        return $this->redirectToRoute('console_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/tenants/{slug}/invite', name: 'tenant_invite', methods: ['POST'])]
    public function invite(string $slug, Request $request): Response
    {
        $tenant = $this->tenantOr404($slug);
        $form = $this->inviteForm($tenant)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->inviteAdmin($tenant, self::text($form, 'email'), $request);
        }

        return $this->redirectToRoute('console_tenant_show', ['slug' => $tenant->slug], Response::HTTP_SEE_OTHER);
    }

    #[Route('/tenants/{slug}/members/{key}/admin', name: 'member_admin', requirements: ['key' => '[0-9a-f]{12}'], methods: ['POST'])]
    public function toggleAdmin(string $slug, string $key, Request $request): Response
    {
        $tenant = $this->tenantOr404($slug);
        $member = $this->memberOr404($tenant, $key);
        if ($this->actionForm('admin_'.$key, 'console_member_admin', $tenant, ['key' => $key])->handleRequest($request)->isSubmitted()) {
            $made = $this->sessions->within($tenant, function () use ($member): bool {
                // The role exists even where access:sync never ran.
                $this->accessSetup->syncAll();
                $roles = $this->roles->rolesOf($member->email);
                $admin = \in_array(Permissions::SUPER_ADMIN_ROLE, $roles, true);
                $this->roles->setRoles($member->email, $admin
                    ? array_values(array_diff($roles, [Permissions::SUPER_ADMIN_ROLE]))
                    : [...$roles, Permissions::SUPER_ADMIN_ROLE]);

                return !$admin;
            });
            $this->log($made ? 'gave the super admin role' : 'took the super admin role', $tenant, $member->email);
        }

        return $this->redirectToRoute('console_tenant_show', ['slug' => $tenant->slug], Response::HTTP_SEE_OTHER);
    }

    #[Route('/tenants/{slug}/members/{key}/remove', name: 'member_remove', requirements: ['key' => '[0-9a-f]{12}'], methods: ['POST'])]
    public function removeMember(string $slug, string $key, Request $request): Response
    {
        $tenant = $this->tenantOr404($slug);
        $member = $this->memberOr404($tenant, $key);
        if ($this->actionForm('remove_'.$key, 'console_member_remove', $tenant, ['key' => $key])->handleRequest($request)->isSubmitted()) {
            $this->users->removeFromTenant($member->email, $tenant->id);
            $this->log('removed member', $tenant, $member->email);
            $this->addFlash('success', $this->translator->trans('admin.members.removed', ['%name%' => $member->displayName]));
        }

        return $this->redirectToRoute('console_tenant_show', ['slug' => $tenant->slug], Response::HTTP_SEE_OTHER);
    }

    /**
     * An admin of the tenant, invited: they hold its super admin role once they accept.
     */
    private function inviteAdmin(Tenant $tenant, string $email, Request $request): void
    {
        $email = $this->users->canonicalEmail($email) ?? mb_strtolower(trim($email));
        try {
            $token = $this->users->invite($email, $tenant->id, $this->operator(), true);
        } catch (UserException $e) {
            $this->addFlash('error', $e->getMessage());

            return;
        }
        // The role is keyed by the email: theirs as soon as they accept, with an account or a new one.
        $this->sessions->within($tenant, function () use ($email): void {
            // The role exists even where access:sync never ran.
            $this->accessSetup->syncAll();
            $this->roles->setRoles($email, array_values(array_unique([...$this->roles->rolesOf($email), Permissions::SUPER_ADMIN_ROLE])));
        });
        $this->log('invited an admin', $tenant, $email);
        $this->addFlash('success', $this->translator->trans('admin.members.invited', ['%email%' => $email]));
        $this->addFlash('invitation', $this->tenantUrl($tenant, '/invitation/'.$token, $request));
    }

    /**
     * The tenant's own address: <slug>.<base host>, the default tenant's the base host itself,
     * with the console's scheme and port.
     */
    private function tenantUrl(Tenant $tenant, string $path, Request $request): string
    {
        $port = $request->getPort();
        $port = null === $port || \in_array($port, [80, 443], true) ? '' : ':'.$port;
        $host = $tenant->slug === $this->default ? $this->baseHost : $tenant->slug.'.'.$this->baseHost;

        return \sprintf('%s://%s%s%s', $request->getScheme(), $host, $port, $path);
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return FormInterface<mixed>
     */
    private function actionForm(string $name, string $route, Tenant $tenant, array $parameters = []): FormInterface
    {
        return $this->forms->createNamedBuilder($name, FormType::class, null, [
            'action' => $this->generateUrl($route, ['slug' => $tenant->slug, ...$parameters]),
        ])->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function inviteForm(Tenant $tenant): FormInterface
    {
        return $this->forms->createNamedBuilder('invite_admin', FormType::class, null, [
            'action' => $this->generateUrl('console_tenant_invite', ['slug' => $tenant->slug]),
        ])->add('email', EmailType::class, ['label' => 'admin.members.email'])->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function deleteForm(Tenant $tenant): FormInterface
    {
        return $this->forms->createNamedBuilder('delete_tenant', FormType::class, null, [
            'action' => $this->generateUrl('console_tenant_delete', ['slug' => $tenant->slug]),
        ])->add('confirm', TextType::class, ['label' => 'console.confirm', 'help' => 'console.confirm_help'])->getForm();
    }

    private function tenantOr404(string $slug): Tenant
    {
        return $this->tenants->bySlug($slug) ?? throw $this->createNotFoundException(\sprintf('No tenant "%s".', $slug));
    }

    private function memberOr404(Tenant $tenant, string $key): Member
    {
        foreach ($this->users->members($tenant->id) as $member) {
            if (self::key($member) === $key) {
                return $member;
            }
        }

        throw $this->createNotFoundException('No such member.');
    }

    private function operator(): string
    {
        return $this->getUser()?->getUserIdentifier() ?? '';
    }

    /**
     * Every action of the console, logged: who, what, which tenant.
     */
    private function log(string $action, Tenant $tenant, ?string $email = null): void
    {
        $this->logger->notice('Operator {operator} {action} {tenant}{email}', [
            'operator' => $this->operator(),
            'action' => $action,
            'tenant' => $tenant->slug,
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
}
