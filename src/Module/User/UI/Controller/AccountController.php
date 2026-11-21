<?php

declare(strict_types=1);

namespace App\Module\User\UI\Controller;

use App\Module\User\Contract\Dto\Profile;
use App\Module\User\Contract\Exception\UserException;
use App\Module\User\Contract\UserManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The signed-in user's own account: their name, their password, the language and the colour
 * theme of the application. Accounts are global: the same page in every workspace, and on the console.
 */
final class AccountController extends AbstractController
{
    /** The browser's copy of the theme, read by base.html.twig before anyone signs in. */
    private const string THEME_COOKIE = 'md_theme';

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

        $notifications = $this->forms->createNamedBuilder('notifications', FormType::class, ['by_email' => $profile->notifyByEmail])
            ->add('by_email', CheckboxType::class, ['label' => 'account.by_email', 'help' => 'account.by_email_help', 'required' => false])
            ->getForm()
            ->handleRequest($request);
        if ($notifications->isSubmitted() && $notifications->isValid()) {
            $this->users->chooseNotificationsByEmail($email, true === $notifications->get('by_email')->getData());
            $this->addFlash('success', $this->translator->trans('account.notifications_saved'));

            return $this->redirectToRoute('app_account', [], Response::HTTP_SEE_OTHER);
        }

        $appearance = $this->forms->createNamedBuilder('appearance', FormType::class, ['theme' => $profile->theme ?? ''])
            ->add('theme', ChoiceType::class, [
                'label' => 'account.theme',
                'help' => 'account.theme_help',
                'choices' => ['account.theme_system' => '', 'account.theme_light' => 'light', 'account.theme_dark' => 'dark'],
                'expanded' => true,
                'placeholder' => false,
                'attr' => ['class' => 'gv-segmented'],
            ])
            ->getForm()
            ->handleRequest($request);
        if ($appearance->isSubmitted() && $appearance->isValid()) {
            $theme = self::nullable($appearance, 'theme');
            $this->users->changeTheme($email, $theme);
            $this->addFlash('success', $this->translator->trans('account.theme_saved'));

            return self::withThemeCookie($this->redirectToRoute('app_account', [], Response::HTTP_SEE_OTHER), $theme);
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
            'notifications_form' => $notifications->createView(),
            'appearance_form' => $appearance->createView(),
            'password_form' => $password->createView(),
        ], new Response(status: ($name->isSubmitted() || $notifications->isSubmitted() || $appearance->isSubmitted() || $password->isSubmitted()) ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
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
     * Saves the theme forced by the toggle of the top bar ("" goes back to the system's). Sent in
     * the background (theme-toggle Stimulus controller), which has switched the page already.
     */
    #[Route('/account/theme', name: 'app_account_theme', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[IsCsrfTokenValid('submit', tokenKey: '_token')]
    public function theme(Request $request): Response
    {
        $theme = $request->getPayload()->getString('theme');
        try {
            $this->users->changeTheme($this->getUser()?->getUserIdentifier() ?? '', '' === $theme ? null : $theme);
        } catch (UserException $e) {
            return new Response($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return self::withThemeCookie(new Response(status: Response::HTTP_NO_CONTENT), '' === $theme ? null : $theme);
    }

    /**
     * The browser keeps a copy of the theme for the pages before signing in (the sign-in page):
     * the profile's, set; the system's, removed.
     */
    private static function withThemeCookie(Response $response, ?string $theme): Response
    {
        null !== $theme && \in_array($theme, Profile::THEMES, true)
            ? $response->headers->setCookie(Cookie::create(self::THEME_COOKIE, $theme, new \DateTimeImmutable('+1 year'), httpOnly: false, sameSite: Cookie::SAMESITE_LAX))
            : $response->headers->clearCookie(self::THEME_COOKIE, httpOnly: false, sameSite: Cookie::SAMESITE_LAX);

        return $response;
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
