<?php

declare(strict_types=1);

namespace App\Module\Notification\Application;

/**
 * A notification as its recipient sees it.
 */
final readonly class NotificationView
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $text,
        public ?string $link,
        public ?string $origin,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $readAt,
    ) {
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }
}
