<?php

declare(strict_types=1);

namespace App\Module\Notification\Application;

use App\Module\Notification\Contract\Dto\NotificationMessage;
use App\Module\Notification\Contract\Dto\Notified;
use App\Module\Notification\Contract\NotifierInterface;
use App\Module\User\Contract\Dto\Member;
use App\Module\User\Contract\UserManagerInterface;
use App\Shared\Workspace\CurrentWorkspace;
use Symfony\Component\Notifier\NotifierInterface as SymfonyNotifier;
use Symfony\Component\Notifier\Recipient\Recipient;

/**
 * The members are read once per call, with their choices (by email, the language): a
 * notification is never sent to someone outside the workspace, whatever the caller passes.
 */
final class Notifier implements NotifierInterface
{
    public function __construct(
        private readonly UserManagerInterface $users,
        private readonly CurrentWorkspace $workspace,
        private readonly SymfonyNotifier $notifier,
    ) {
    }

    public function notify(array $emails, NotificationMessage $message, bool $byEmail = true): Notified
    {
        /** @var array<string, Member> $members */
        $members = [];
        foreach ($this->users->members($this->workspace->id()) as $member) {
            if ($member->active) {
                $members[$member->email] = $member;
            }
        }
        $notified = 0;
        $leftOut = 0;
        $emailed = 0;
        foreach (array_unique(array_map(static fn (string $email): string => mb_strtolower(trim($email)), $emails)) as $email) {
            $member = $members[$email] ?? null;
            if (null === $member) {
                ++$leftOut;
                continue;
            }
            $email = $byEmail && $member->notifyByEmail;
            $this->notifier->send(new WorkspaceNotification($message, $email, $member->locale), new Recipient($member->email));
            ++$notified;
            $emailed += (int) $email;
        }

        return new Notified($notified, $leftOut, $emailed);
    }
}
