<?php

declare(strict_types=1);

namespace App\Module\Notification\UI\Console;

use App\Module\Notification\Application\NotificationStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The notifications are kept a while, read or not, then deleted. For the command's workspace;
 * every workspace with workspace:run --all.
 */
#[AsCommand(name: 'notifications:gc', description: 'Delete the notifications older than the days they are kept')]
final class CollectNotificationsConsoleCommand
{
    public function __construct(
        private readonly NotificationStore $notifications,
        #[Autowire('%app.notification.keep_days%')]
        private readonly int $keepDays,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Keep the notifications of the last days, instead of app.notification.keep_days')] ?int $days = null,
    ): int {
        $days ??= $this->keepDays;
        if ($days < 1) {
            $io->error('Keep the notifications one day at least.');

            return Command::INVALID;
        }
        $deleted = $this->notifications->deleteOlderThan(new \DateTimeImmutable(\sprintf('-%d days', $days)));
        $io->success(\sprintf('%d notification(s) older than %d days deleted.', $deleted, $days));

        return Command::SUCCESS;
    }
}
