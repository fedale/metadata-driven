<?php

declare(strict_types=1);

namespace App\Module\Notification\Infrastructure\Channel;

use App\Module\Notification\Application\NotificationStore;
use App\Module\Notification\Application\WorkspaceNotification;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Notifier\Channel\ChannelInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Recipient\EmailRecipientInterface;
use Symfony\Component\Notifier\Recipient\RecipientInterface;

/**
 * The bell: the notification stored for its recipient, in the current workspace. Symfony Notifier
 * has no channel that stores; this is the application's.
 */
#[AutoconfigureTag('notifier.channel', ['channel' => 'inbox'])]
final class InboxChannel implements ChannelInterface
{
    public function __construct(
        private readonly NotificationStore $store,
    ) {
    }

    public function notify(Notification $notification, RecipientInterface $recipient, ?string $transportName = null): void
    {
        if (!$notification instanceof WorkspaceNotification || !$recipient instanceof EmailRecipientInterface) {
            throw new \LogicException(\sprintf('The inbox takes a %s to an email, not a %s to a %s.', WorkspaceNotification::class, get_debug_type($notification), get_debug_type($recipient)));
        }
        $this->store->add($recipient->getEmail(), $notification->message);
    }

    public function supports(Notification $notification, RecipientInterface $recipient): bool
    {
        return $notification instanceof WorkspaceNotification && $recipient instanceof EmailRecipientInterface && '' !== $recipient->getEmail();
    }
}
