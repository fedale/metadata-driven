<?php

declare(strict_types=1);

namespace App\Module\Notification\Contract\Dto;

/**
 * Who a notification reached: the members notified, and how many addresses were left out (no
 * member of the workspace, or an account disabled).
 */
final readonly class Notified
{
    public function __construct(
        public int $notified,
        public int $leftOut,
    ) {
    }
}
