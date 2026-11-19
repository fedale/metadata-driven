<?php

declare(strict_types=1);

namespace App\Module\Notification\Application;

use App\Module\Notification\Contract\Dto\NotificationMessage;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Recipient\RecipientInterface;

/**
 * A notification to one member, through Symfony Notifier: the bell always ("inbox"), an email
 * too ("mail") when the member chose it. Its channels are the member's own, so the policy of
 * notifier.yaml is never asked.
 */
final class WorkspaceNotification extends Notification
{
    public function __construct(
        public readonly NotificationMessage $message,
        private readonly bool $byEmail,
        /** The member's language, for the email; null: the application's. */
        public readonly ?string $locale,
    ) {
        parent::__construct($message->title);
        $this->content($message->text ?? '');
    }

    public function getChannels(RecipientInterface $recipient): array
    {
        return $this->byEmail ? ['inbox', 'mail'] : ['inbox'];
    }
}
