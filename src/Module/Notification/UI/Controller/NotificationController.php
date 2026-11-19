<?php

declare(strict_types=1);

namespace App\Module\Notification\UI\Controller;

use App\Module\Notification\Application\NotificationStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The signed-in user's notifications in the current workspace: the bell's menu and count, the
 * history. Each query names the user as recipient: no one reads another's notifications, and
 * row-level security keeps them to the workspace. The console has none (HostWorkspaceListener
 * keeps it to its own pages).
 */
#[Route('/notifications', name: 'notification_')]
#[IsGranted('IS_AUTHENTICATED')]
final class NotificationController extends AbstractController
{
    private const int PER_PAGE = 25;

    /** The bell's menu: the latest ones. */
    private const int MENU = 10;

    public function __construct(
        private readonly NotificationStore $notifications,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $total = $this->notifications->count($this->me());
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));

        return $this->render('notification/index.html.twig', [
            'notifications' => $this->notifications->latest($this->me(), self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'page' => $page,
            'pages' => $pages,
            'unread' => $this->notifications->unreadCount($this->me()),
        ]);
    }

    /**
     * The bell's menu, a Turbo Frame loaded when the bell opens.
     */
    #[Route('/menu', name: 'menu', methods: ['GET'])]
    public function menu(): Response
    {
        return $this->render('notification/_menu.html.twig', [
            'notifications' => $this->notifications->latest($this->me(), self::MENU),
        ]);
    }

    /**
     * The bell's count, asked every minute by its Stimulus controller.
     */
    #[Route('/count', name: 'count', methods: ['GET'])]
    public function count(): JsonResponse
    {
        $response = new JsonResponse(['unread' => $this->notifications->unreadCount($this->me())]);
        $response->setPrivate();

        return $response;
    }

    /**
     * Follows a notification: read, then its page. A link is a path of the workspace
     * (NotificationMessage), so this never leads away from the application.
     */
    #[Route('/{id}/open', name: 'open', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function open(string $id): Response
    {
        $notification = $this->notifications->find($this->me(), $id) ?? throw $this->createNotFoundException('No such notification.');
        $this->notifications->markRead($this->me(), $id);

        return null === $notification->link
            ? $this->redirectToRoute('notification_index', [], Response::HTTP_SEE_OTHER)
            : $this->redirect($notification->link, Response::HTTP_SEE_OTHER);
    }

    #[Route('/read-all', name: 'read_all', methods: ['POST'])]
    #[IsCsrfTokenValid('submit', tokenKey: '_token')]
    public function readAll(): Response
    {
        $this->notifications->markAllRead($this->me());
        $this->addFlash('success', $this->translator->trans('notifications.all_read'));

        return $this->redirectToRoute('notification_index', [], Response::HTTP_SEE_OTHER);
    }

    private function me(): string
    {
        return $this->getUser()?->getUserIdentifier() ?? throw $this->createAccessDeniedException();
    }
}
