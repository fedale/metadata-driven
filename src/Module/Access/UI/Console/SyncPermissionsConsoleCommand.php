<?php

declare(strict_types=1);

namespace App\Module\Access\UI\Console;

use App\Module\Access\Application\PermissionSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'access:sync', description: 'Create the permissions of every object, the admin permission and the super admin role')]
final class SyncPermissionsConsoleCommand
{
    public function __construct(
        private readonly PermissionSynchronizer $permissions,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $created = $this->permissions->syncAll();

        if ([] === $created) {
            $io->success('Every permission already exists.');
        } else {
            $io->listing($created);
            $io->success(\sprintf('%d item(s) created.', \count($created)));
        }

        return Command::SUCCESS;
    }
}
