<?php

declare(strict_types=1);

namespace App\Module\Admin\UI\Controller;

use App\Module\Access\Contract\TenantRolesInterface;
use App\Module\User\Contract\Exception\UserException;
use App\Module\User\Contract\UserManagerInterface;
use App\Shared\Tenant\CurrentTenant;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An invitation to the tenant of the host, opened by whoever got its link
 * (docs/internal/tenant-admin-ui-plan.md, part A2). Public: its token is the credential.
 *
 * The page is the same whether the email has an account or not: a password, and a name for a
 * new account. An existing account proves itself with its password, and a wrong one counts
 * against the invitation, burned at the fifth.
 */
final class InvitationController extends AbstractController
{
    /** The account that just accepted, to sign in at the next request (welcome()). */
    private const string ACCEPTED = '_invitation_accepted';

    /**
     * @param UserProviderInterface<UserInterface> $userProvider
     */
    public function __construct(
        private readonly UserManagerInterface $users,
        private readonly TenantRolesInterface $roles,
        private readonly CurrentTenant $tenant,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        #[Autowire(service: 'security.user.provider.concrete.app_users')]
        private readonly UserProviderInterface $userProvider,
    ) {
    }

    #[Route('/invitation/{token}', name: 'invitation_show', requirements: ['token' => '[A-Za-z0-9_-]{43}'], methods: ['GET', 'POST'])]
    public function show(string $token, Request $request): Response
    {
        $invitation = $this->users->openInvitation($this->tenant->id(), $token)
            ?? throw $this->createNotFoundException('This invitation is no longer valid.');

        $form = $this->form()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $accepted = $this->users->acceptInvitation(
                    $this->tenant->id(),
                    $token,
                    self::text($form, 'password'),
                    self::nullable($form, 'first_name'),
                    self::nullable($form, 'last_name'),
                );
                // Its inviter didn't hold all the account had here: none of it comes back.
                if (!$accepted->keepAssignments) {
                    $this->roles->revokeAll($accepted->email);
                }
                // Signed in at the next request, not this one: fedale/rbac-bundle keeps the
                // assignments it read during a request, so a user loaded now would carry the
                // roles just revoked, and be signed out at once for it.
                $request->getSession()->set(self::ACCEPTED, $accepted->email);

                return $this->redirectToRoute('invitation_welcome', [], Response::HTTP_SEE_OTHER);
            } catch (UserException $e) {
                $form->get('password')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('security/invitation.html.twig', [
            'email' => $invitation->email,
            'form' => $form->createView(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/invitation/welcome', name: 'invitation_welcome', methods: ['GET'])]
    public function welcome(Request $request): Response
    {
        $email = $request->getSession()->remove(self::ACCEPTED);
        if (!\is_string($email)) {
            return $this->redirectToRoute('app_login');
        }
        $this->security->login($this->userProvider->loadUserByIdentifier($email), 'form_login', 'main');
        $this->addFlash('success', $this->translator->trans('invitation.welcome'));

        return $this->redirectToRoute('app_home', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function form(): FormInterface
    {
        return $this->forms->createNamedBuilder('invitation', FormType::class)
            ->add('password', PasswordType::class, ['label' => 'invitation.password', 'help' => 'invitation.password_help'])
            ->add('first_name', TextType::class, ['label' => 'invitation.first_name', 'required' => false])
            ->add('last_name', TextType::class, ['label' => 'invitation.last_name', 'required' => false])
            ->getForm();
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private static function text(FormInterface $form, string $child): string
    {
        $value = $form->get($child)->getData();

        return \is_string($value) ? $value : '';
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private static function nullable(FormInterface $form, string $child): ?string
    {
        $value = trim(self::text($form, $child));

        return '' === $value ? null : $value;
    }
}
