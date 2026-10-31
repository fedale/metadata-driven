<?php

declare(strict_types=1);

namespace App\Module\User\UI\Controller;

use App\Module\User\Contract\Exception\UserException;
use App\Module\User\Contract\UserManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The preferences of the signed-in user: the language of the application.
 */
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly UserManagerInterface $users,
    ) {
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
