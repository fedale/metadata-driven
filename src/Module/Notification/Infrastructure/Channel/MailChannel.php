<?php

declare(strict_types=1);

namespace App\Module\Notification\Infrastructure\Channel;

use App\Module\Notification\Application\WorkspaceNotification;
use App\Shared\Mail\Mail;
use App\Shared\Mail\Mailer;
use App\Shared\Mail\MailNotSent;
use App\Shared\Workspace\WorkspaceUrls;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Notifier\Channel\ChannelInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Recipient\EmailRecipientInterface;
use Symfony\Component\Notifier\Recipient\RecipientInterface;

/**
 * The email of a notification, for a member who chose it: through the application's Mailer
 * rather than Notifier's own email channel, so that it has the sender, the layout and the
 * language of every other email of the application.
 *
 * Its bell is stored already: an email that cannot be sent is logged, never thrown, so that it
 * fails neither the notification nor what sent it (a workflow's run).
 */
#[AutoconfigureTag('notifier.channel', ['channel' => 'mail'])]
final class MailChannel implements ChannelInterface
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly WorkspaceUrls $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notify(Notification $notification, RecipientInterface $recipient, ?string $transportName = null): void
    {
        if (!$notification instanceof WorkspaceNotification || !$recipient instanceof EmailRecipientInterface) {
            throw new \LogicException(\sprintf('The mail channel takes a %s to an email, not a %s to a %s.', WorkspaceNotification::class, get_debug_type($notification), get_debug_type($recipient)));
        }
        $message = $notification->message;
        try {
            $this->mailer->send(new Mail(
                $recipient->getEmail(),
                'notification',
                'notification.subject',
                ['%title%' => $message->title],
                [
                    'title' => $message->title,
                    'text' => $message->text,
                    'origin' => $message->origin,
                    'link' => null === $message->link ? null : $this->urls->ofCurrent($message->link),
                    'account' => $this->urls->ofCurrent('/account'),
                ],
                $notification->locale,
            ));
        } catch (MailNotSent $e) {
            $this->logger->error('The email of a notification could not be sent: {message}', ['message' => $e->getMessage()]);
        }
    }

    public function supports(Notification $notification, RecipientInterface $recipient): bool
    {
        return $notification instanceof WorkspaceNotification && $recipient instanceof EmailRecipientInterface && '' !== $recipient->getEmail();
    }
}
