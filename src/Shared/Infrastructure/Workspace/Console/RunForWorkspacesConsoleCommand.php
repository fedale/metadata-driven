<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace\Console;

use App\Shared\Infrastructure\Workspace\WorkspaceRegistry;
use App\Shared\Workspace\Workspace;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Runs a command for several workspaces: workspace:run --all -- metadata:package:upgrade acme/crm.
 *
 * One process per workspace, so that nothing one workspace's run leaves in memory (entities, caches,
 * the session's workspace) reaches the next. It carries on past a failure, reports each workspace's
 * outcome, and fails when one of them did. Suspended workspaces are left out: their commands
 * refuse to run.
 */
#[AsCommand(name: 'workspace:run', description: 'Run a command for every workspace, or for some: workspace:run --all -- <command> [arguments]')]
final class RunForWorkspacesConsoleCommand
{
    public function __construct(
        private readonly WorkspaceRegistry $workspaces,
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
        #[Option('Every active workspace')] bool $all = false,
        #[Option('A workspace to run it for (repeatable)')] array $only = [],
    ): int {
        if ([] === $commandLine) {
            $io->error('Name the command to run, after "--": workspace:run --all -- metadata:list');

            return Command::INVALID;
        }
        if ($all === ([] !== $only)) {
            $io->error('Choose the workspaces: --all, or --only=<slug> once per workspace.');

            return Command::INVALID;
        }
        $workspaces = $this->select($all, $only, $io);
        if (null === $workspaces) {
            return Command::FAILURE;
        }

        $failed = [];
        foreach ($workspaces as $workspace) {
            $io->section($workspace->slug);
            $process = new Process([
                \PHP_BINARY,
                $this->projectDir.'/bin/console',
                ...$commandLine,
                '--workspace='.$workspace->slug,
                '--env='.$this->environment,
                '--no-interaction',
            ]);
            $process->setTimeout(null);
            $process->run(static function (string $type, string $buffer) use ($io): void {
                $io->write($buffer);
            });
            if (!$process->isSuccessful()) {
                $failed[] = $workspace->slug;
            }
        }

        if ([] !== $failed) {
            $io->error(\sprintf('Failed for %d of %d workspaces: %s.', \count($failed), \count($workspaces), implode(', ', $failed)));

            return Command::FAILURE;
        }
        $io->success(\sprintf('Done for %d workspaces.', \count($workspaces)));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $only
     *
     * @return list<Workspace>|null null when a slug names no workspace
     */
    private function select(bool $all, array $only, SymfonyStyle $io): ?array
    {
        if ($all) {
            return array_values(array_filter($this->workspaces->all(), static fn (Workspace $workspace): bool => $workspace->active));
        }
        $workspaces = [];
        foreach ($only as $slug) {
            $workspace = $this->workspaces->bySlug($slug);
            if (null === $workspace) {
                $io->error(\sprintf('There is no workspace "%s".', $slug));

                return null;
            }
            $workspaces[] = $workspace;
        }

        return $workspaces;
    }
}
