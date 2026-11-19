<?php

declare(strict_types=1);

namespace App\Module\Notification\Application;

use App\Module\Notification\Contract\Dto\NotificationMessage;

/**
 * core.notification, the current workspace's (row-level security): every read names its
 * recipient, the canonical email of an account, so that a user reads their own only.
 */
interface NotificationStore
{
    public function add(string $recipient, NotificationMessage $message): void;

    public function unreadCount(string $recipient): int;

    public function count(string $recipient): int;

    /**
     * @return list<NotificationView> the newest first
     */
    public function latest(string $recipient, int $limit, int $offset = 0): array;

    public function find(string $recipient, string $id): ?NotificationView;

    public function markRead(string $recipient, string $id): void;

    /**
     * @return int how many were unread
     */
    public function markAllRead(string $recipient): int;

    /**
     * @return int how many were deleted
     */
    public function deleteOlderThan(\DateTimeImmutable $before): int;
}
