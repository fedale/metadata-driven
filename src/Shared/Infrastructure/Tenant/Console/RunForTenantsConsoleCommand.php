<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant\Console;

use App\Shared\Infrastructure\Tenant\TenantRegistry;
use App\Shared\Tenant\Tenant;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Runs a command for several tenants: tenant:run --all -- metadata:template:upgrade acme/crm.
 *
 * One process per tenant, so that nothing one tenant's run leaves in memory (entities, caches,
 * the session's tenant) reaches the next. It carries on past a failure, reports each tenant's
 * outcome, and fails when one of them did. Suspended tenants are left out: their commands
 * refuse to run.
 */
#[AsCommand(name: 'tenant:run', description: 'Run a command for every tenant, or for some: tenant:run --all -- <command> [arguments]')]
final class RunForTenantsConsoleCommand
{
    public function __construct(
        private readonly TenantRegistry $tenants,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    /**
     * @param list<string> $commandLine
     * @param list<string> $only
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The command and its arguments, after "--"')] array $commandLine,
        #[Option('Every active tenant')] bool $all = false,
        #[Option('A tenant to run it for (repeatable)')] array $only = [],
    ): int {
        if ([] === $commandLine) {
            $io->error('Name the command to run, after "--": tenant:run --all -- metadata:list');

            return Command::INVALID;
        }
        if ($all === ([] !== $only)) {
            $io->error('Choose the tenants: --all, or --only=<slug> once per tenant.');

            return Command::INVALID;
        }
        $tenants = $this->select($all, $only, $io);
        if (null === $tenants) {
            return Command::FAILURE;
        }

        $failed = [];
        foreach ($tenants as $tenant) {
            $io->section($tenant->slug);
            $process = new Process([
                \PHP_BINARY,
                $this->projectDir.'/bin/console',
                ...$commandLine,
                '--tenant='.$tenant->slug,
                '--env='.$this->environment,
                '--no-interaction',
            ]);
            $process->setTimeout(null);
            $process->run(static function (string $type, string $buffer) use ($io): void {
                $io->write($buffer);
            });
            if (!$process->isSuccessful()) {
                $failed[] = $tenant->slug;
            }
        }

        if ([] !== $failed) {
            $io->error(\sprintf('Failed for %d of %d tenants: %s.', \count($failed), \count($tenants), implode(', ', $failed)));

            return Command::FAILURE;
        }
        $io->success(\sprintf('Done for %d tenants.', \count($tenants)));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $only
     *
     * @return list<Tenant>|null null when a slug names no tenant
     */
    private function select(bool $all, array $only, SymfonyStyle $io): ?array
    {
        if ($all) {
            return array_values(array_filter($this->tenants->all(), static fn (Tenant $tenant): bool => $tenant->active));
        }
        $tenants = [];
        foreach ($only as $slug) {
            $tenant = $this->tenants->bySlug($slug);
            if (null === $tenant) {
                $io->error(\sprintf('There is no tenant "%s".', $slug));

                return null;
            }
            $tenants[] = $tenant;
        }

        return $tenants;
    }
}
