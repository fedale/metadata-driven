<?php

declare(strict_types=1);

namespace App\Module\Notification\Contract;

use App\Module\Notification\Contract\Dto\NotificationMessage;
use App\Module\Notification\Contract\Dto\Notified;

/**
 * Notifies members of the current workspace: the bell of each, and an email to those who chose
 * it (on their account page), in their language.
 */
interface NotifierInterface
{
    /**
     * Only the workspace's active members are notified: any other address is left out, and
     * counted. An address given twice is notified once.
     *
     * @param list<string> $emails
     * @param bool         $byEmail false: the bell only, whatever the members chose (a workflow
     *                              past its plan's emails)
     *
     * @throws \LogicException no workspace is selected
     */
    public function notify(array $emails, NotificationMessage $message, bool $byEmail = true): Notified;
}
