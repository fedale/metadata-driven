<?php

declare(strict_types=1);

namespace App\Module\Notification\UI\Twig;

use App\Module\Notification\Application\NotificationStore;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/**
 * The count of the bell in the top bar: the signed-in user's unread notifications in the current
 * workspace. Read per call, never kept: the service lives across requests in a worker.
 */
final class NotificationBell
{
    public function __construct(
        private readonly NotificationStore $notifications,
        private readonly Security $security,
    ) {
    }

    #[AsTwigFunction('unread_notifications')]
    public function unread(): int
    {
        $user = $this->security->getUser();

        return null === $user ? 0 : $this->notifications->unreadCount($user->getUserIdentifier());
    }
}
