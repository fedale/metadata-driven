<?php

declare(strict_types=1);

namespace App\Module\Workflow\UI\Console;

use App\Module\Workflow\Application\Secrets\SecretBox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A new key for the workflows' secrets, printed, kept nowhere: the installation sets it as
 * WORKFLOW_SECRETS_KEY. Changing it leaves the secrets written with the old one unreadable.
 */
#[AsCommand(name: 'workflow:secrets:key', description: 'Print a new key for WORKFLOW_SECRETS_KEY, the one the workflows\' secrets are encrypted with')]
final class SecretsKeyConsoleCommand
{
    public function __invoke(SymfonyStyle $io): int
    {
        $io->writeln(SecretBox::generateKey());
        $io->note('Set it as WORKFLOW_SECRETS_KEY: in .env.local, or in the vault (secrets:set WORKFLOW_SECRETS_KEY). The secrets written with another key can no longer be read: write them again.');

        return Command::SUCCESS;
    }
}
