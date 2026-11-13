<?php

declare(strict_types=1);

namespace App\Module\User\UI\Controller;

use App\Module\User\Contract\Exception\UserException;
use App\Module\User\Contract\UserManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The signed-in user's own account: their name, their password, the language of the
 * application. Accounts are global: the same page in every tenant, and on the console.
 */
final class AccountController extends AbstractController
{
    /**
     * @param UserProviderInterface<UserInterface> $userProvider
     */
    public function __construct(
        private readonly UserManagerInterface $users,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        #[Autowire(service: 'security.user.provider.concrete.app_users')]
        private readonly UserProviderInterface $userProvider,
    ) {
    }

    #[Route('/account', name: 'app_account', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function show(Request $request): Response
    {
        $email = $this->getUser()?->getUserIdentifier() ?? '';
        $profile = $this->users->profile($email);

        $name = $this->forms->createNamedBuilder('profile', FormType::class, ['first_name' => $profile->firstName, 'last_name' => $profile->lastName])
            ->add('first_name', TextType::class, ['label' => 'account.first_name', 'required' => false])
            ->add('last_name', TextType::class, ['label' => 'account.last_name', 'required' => false])
            ->getForm()
            ->handleRequest($request);
        if ($name->isSubmitted() && $name->isValid()) {
            $this->users->rename($email, self::nullable($name, 'first_name'), self::nullable($name, 'last_name'));
            $this->addFlash('success', $this->translator->trans('account.renamed'));

            return $this->signedInAgain($email);
        }

        $password = $this->forms->createNamedBuilder('password', FormType::class)
            ->add('current', PasswordType::class, ['label' => 'account.current_password'])
            ->add('new', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => $this->translator->trans('account.passwords_differ'),
                'first_options' => ['label' => 'account.new_password', 'help' => 'account.new_password_help'],
                'second_options' => ['label' => 'account.repeat_password'],
            ])
            ->getForm()
            ->handleRequest($request);
        if ($password->isSubmitted() && $password->isValid()) {
            try {
                $this->users->changePassword($email, self::text($password, 'current'), self::text($password, 'new'));
                $this->addFlash('success', $this->translator->trans('account.password_changed'));

                return $this->signedInAgain($email);
            } catch (UserException $e) {
                $password->get('current')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('account/show.html.twig', [
            'email' => $profile->email,
            'name_form' => $name->createView(),
            'password_form' => $password->createView(),
        ], new Response(status: ($name->isSubmitted() || $password->isSubmitted()) ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Saves the language picked in the top bar ("" goes back to the browser's), then returns
     * to the page it was picked on.
     */
    #[Route('/account/locale', name: 'app_account_locale', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[IsCsrfTokenValid('submit', tokenKey: '_token')]
    public function locale(Request $request): Response
    {
        $locale = $request->getPayload()->getString('locale');
        try {
            $this->users->changeLocale($this->getUser()?->getUserIdentifier() ?? '', '' === $locale ? null : $locale);
        } catch (UserException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->backTo($request), Response::HTTP_SEE_OTHER);
    }

    /**
     * The session goes on with the account as changed. A new password changes the hash every
     * session holds (SecurityUser::isEqualTo()): the others end, this one is signed in again.
     * The name is part of the session's user too.
     */
    private function signedInAgain(string $email): Response
    {
        $this->security->login($this->userProvider->loadUserByIdentifier($email), 'form_login', 'main');

        return $this->redirectToRoute('app_account', [], Response::HTTP_SEE_OTHER);
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

    /**
     * The page the form was sent from, on this site only: the path of the Referer.
     */
    private function backTo(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');
        $parts = parse_url($referer);
        if (false === $parts || ($parts['host'] ?? null) !== $request->getHost() || !str_starts_with($parts['path'] ?? '', '/')) {
            return $this->generateUrl('app_home');
        }

        return $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
