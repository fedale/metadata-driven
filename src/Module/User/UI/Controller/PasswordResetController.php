<?php

declare(strict_types=1);

namespace App\Module\User\UI\Controller;

use App\Module\User\Contract\Exception\UserException;
use App\Module\User\Contract\UserManagerInterface;
use App\Shared\Mail\Mail;
use App\Shared\Mail\Mailer;
use App\Shared\Mail\MailNotSent;
use App\Shared\Workspace\SystemConsole;
use App\Shared\Workspace\WorkspaceUrls;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Email as EmailConstraint;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Forgot your password?": a link by email, on whatever host it was asked (accounts are global),
 * working an hour and once.
 *
 * The answer is the same whether an account has the address or not, and the requests are limited
 * per address and per IP before anything is looked up: the page tells nothing of the accounts,
 * and is no way to mail an address over and over.
 */
final class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly UserManagerInterface $users,
        private readonly Mailer $mailer,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly WorkspaceUrls $urls,
        // config/packages/rate_limiter.yaml
        private readonly RateLimiterFactoryInterface $passwordResetRequestLimiter,
    ) {
    }

    #[Route('/password/forgot', name: 'password_forgot', methods: ['GET', 'POST'])]
    public function forgot(Request $request): Response
    {
        $form = $this->forgotForm()->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('security/password_forgot.html.twig', ['form' => $form->createView()], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        }
        $email = mb_strtolower(trim(self::text($form, 'email')));
        // Both tries taken, whatever the first says: a refused address still counts for the IP.
        $byAddress = $this->passwordResetRequestLimiter->create('email:'.$email)->consume()->isAccepted();
        $byIp = $this->passwordResetRequestLimiter->create('ip:'.$request->getClientIp())->consume()->isAccepted();
        if (!$byAddress || !$byIp) {
            $this->addFlash('error', $this->translator->trans('password_reset.too_many'));

            return $this->redirectToRoute('password_forgot', [], Response::HTTP_SEE_OTHER);
        }
        $reset = $this->users->requestPasswordReset($email);
        if (null !== $reset) {
            try {
                $this->mailer->send(new Mail(
                    $reset->email,
                    'password_reset',
                    'password_reset.subject',
                    context: [
                        'link' => $this->link($request, $reset->token),
                        'expires_at' => $reset->expiresAt,
                    ],
                    locale: $reset->locale ?? $request->getLocale(),
                ));
            } catch (MailNotSent $e) {
                // Told to no one asking: the answer stays the same. The operator finds it here.
                $this->logger->error('The email of a password reset could not be sent: {message}', ['message' => $e->getMessage()]);
            }
        }
        $this->addFlash('success', $this->translator->trans('password_reset.sent'));

        return $this->redirectToRoute('app_login', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/password/reset/{token}', name: 'password_reset', requirements: ['token' => '[A-Za-z0-9_-]{43}'], methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request): Response
    {
        $email = $this->users->openPasswordReset($token)
            ?? throw $this->createNotFoundException('This link to reset the password is no longer valid.');

        $form = $this->resetForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->users->resetPassword($token, self::text($form, 'password'));
                $this->addFlash('success', $this->translator->trans('password_reset.done'));

                return $this->redirectToRoute('app_login', [], Response::HTTP_SEE_OTHER);
            } catch (UserException $e) {
                $form->get('password')->get('first')->addError(new FormError($e->getMessage()));
            }
        }

        $response = $this->render('security/password_reset.html.twig', [
            'email' => $email,
            'form' => $form->createView(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        // The token is in this page's address: no link of it tells it to another site.
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * The link on the host it was asked on, as the configuration names it (WorkspaceUrls), never
     * as the request does: any other host reaches the default workspace, so a request sent with
     * Host: attacker.example would otherwise mail a victim a link handing the token to it.
     */
    private function link(Request $request, string $token): string
    {
        $path = $this->generateUrl('password_reset', ['token' => $token]);

        return true === $request->attributes->get(SystemConsole::REQUEST_ATTRIBUTE) ? $this->urls->ofConsole($path) : $this->urls->ofCurrent($path);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function forgotForm(): FormInterface
    {
        return $this->forms->createNamedBuilder('password_forgot', FormType::class)
            ->add('email', EmailType::class, ['label' => 'login.email', 'constraints' => [new NotBlank(), new EmailConstraint()]])
            ->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function resetForm(): FormInterface
    {
        return $this->forms->createNamedBuilder('password_reset', FormType::class)
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => ['label' => 'password_reset.new_password', 'help' => 'password_reset.password_help'],
                'second_options' => ['label' => 'password_reset.repeat'],
                'invalid_message' => 'password_reset.mismatch',
            ])
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
}
