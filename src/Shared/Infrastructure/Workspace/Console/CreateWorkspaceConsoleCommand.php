<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace\Console;

use App\Shared\Infrastructure\Workspace\WorkspaceProvisioner;
use App\Shared\Workspace\Plans;
use App\Shared\Workspace\WorkspaceCreated;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creates a workspace, ready to use: its schemas and rows (WorkspaceProvisioner), its permissions,
 * its first admin, a package if asked.
 *
 * The steps after the first are the commands that do them for any workspace, run with --workspace:
 * access:sync, user:create or workspace:member:add, rbac:assign, metadata:package:install. The
 * shared layer may not use the modules, and the commands are what a system admin would type.
 * A step that fails stops the command; the workspace stays, and the steps left can be run by hand.
 */
#[AsCommand(name: 'workspace:create', description: 'Create a workspace: its schemas, its permissions, its first admin, a package if asked')]
final class CreateWorkspaceConsoleCommand extends Command
{
    public function __construct(
        private readonly WorkspaceProvisioner $provisioner,
        private readonly Connection $connection,
        #[Autowire('%app.super_admin_role%')]
        private readonly string $superAdminRole,
        private readonly EventDispatcherInterface $events,
        private readonly Plans $plans,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Its slug, the first label of its host: lower case letters, digits and dashes')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Its name, shown to its users (default: the slug)')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Its first admin: an existing user becomes a member, a new one is created')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'The password of a new admin (asked, hidden, when omitted; avoid it in shared shells)')
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'A package to install: vendor/name, vendor/name@1.0.0, or a directory')
            ->addOption('plan', null, InputOption::VALUE_REQUIRED, 'Its plan (config/packages/plans.yaml); default: the default plan');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = $input->getArgument('slug');
        if (!\is_string($slug)) {
            throw new \LogicException('The slug is a required argument.');
        }
        $name = $input->getOption('name');
        $plan = $input->getOption('plan');
        if (\is_string($plan) && null === $this->plans->get($plan)) {
            // Before anything is created.
            $io->error(\sprintf('There is no plan "%s": %s.', $plan, implode(', ', array_keys($this->plans->all()))));

            return Command::FAILURE;
        }

        try {
            $workspace = $this->provisioner->create($slug, \is_string($name) && '' !== $name ? $name : $slug);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if (\is_string($plan)) {
            $this->provisioner->assignPlan($workspace, $plan);
        }
        $io->success(\sprintf('Workspace "%s" created.', $slug));

        $steps = [['command' => 'access:sync']];
        $email = $input->getOption('admin-email');
        if (\is_string($email) && '' !== $email) {
            $steps[] = $this->userExists($email)
                ? ['command' => 'workspace:member:add', 'workspace' => $slug, 'email' => $email]
                : array_filter(['command' => 'user:create', 'email' => $email, '--password' => $input->getOption('admin-password')]);
            $steps[] = ['command' => 'rbac:assign', 'user' => mb_strtolower(trim($email)), 'item' => $this->superAdminRole];
        }
        $package = $input->getOption('package');
        if (\is_string($package) && '' !== $package) {
            $steps[] = ['command' => 'metadata:package:install', 'package' => $package];
        }

        foreach ($steps as $step) {
            $exitCode = $this->runStep($step, $slug, $input, $output);
            if (Command::SUCCESS !== $exitCode) {
                $io->error(\sprintf('"%s" failed: workspace "%s" exists, run the steps left by hand with --workspace=%s.', $step['command'], $slug, $slug));

                return $exitCode;
            }
        }
        // The system admin's workflows run on it, once the command is done.
        $this->events->dispatch(new WorkspaceCreated($workspace));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $step
     */
    private function runStep(array $step, string $slug, InputInterface $input, OutputInterface $output): int
    {
        $application = $this->getApplication() ?? throw new \LogicException('workspace:create runs in an application.');
        // workspace:member:add names its workspace in an argument, and runs as the system.
        if ('workspace:member:add' !== $step['command']) {
            $step['--workspace'] = $slug;
        }
        $stepInput = new ArrayInput($step);
        $stepInput->setInteractive($input->isInteractive());

        // doRun(), not run(): the console events select the step's workspace.
        return $application->doRun($stepInput, $output);
    }

    private function userExists(string $email): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM core.app_user WHERE email = ?', [mb_strtolower(trim($email))]);
    }
}
