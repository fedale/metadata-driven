<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Tenant\Console;

use App\Shared\Infrastructure\Tenant\TenantProvisioner;
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

/**
 * Creates a tenant, ready to use: its schemas and rows (TenantProvisioner), its permissions,
 * its first admin, a template if asked.
 *
 * The steps after the first are the commands that do them for any tenant, run with --tenant:
 * access:sync, user:create or tenant:member:add, rbac:assign, metadata:template:install. The
 * shared layer may not use the modules, and the commands are what an operator would type.
 * A step that fails stops the command; the tenant stays, and the steps left can be run by hand.
 */
#[AsCommand(name: 'tenant:create', description: 'Create a tenant: its schemas, its permissions, its first admin, a template if asked')]
final class CreateTenantConsoleCommand extends Command
{
    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly Connection $connection,
        #[Autowire('%app.super_admin_role%')]
        private readonly string $superAdminRole,
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
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'A template to install: vendor/name, vendor/name@1.0.0, or a directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = $input->getArgument('slug');
        if (!\is_string($slug)) {
            throw new \LogicException('The slug is a required argument.');
        }
        $name = $input->getOption('name');

        try {
            $this->provisioner->create($slug, \is_string($name) && '' !== $name ? $name : $slug);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('Tenant "%s" created.', $slug));

        $steps = [['command' => 'access:sync']];
        $email = $input->getOption('admin-email');
        if (\is_string($email) && '' !== $email) {
            $steps[] = $this->userExists($email)
                ? ['command' => 'tenant:member:add', 'tenant' => $slug, 'email' => $email]
                : array_filter(['command' => 'user:create', 'email' => $email, '--password' => $input->getOption('admin-password')]);
            $steps[] = ['command' => 'rbac:assign', 'user' => mb_strtolower(trim($email)), 'item' => $this->superAdminRole];
        }
        $template = $input->getOption('template');
        if (\is_string($template) && '' !== $template) {
            $steps[] = ['command' => 'metadata:template:install', 'template' => $template];
        }

        foreach ($steps as $step) {
            $exitCode = $this->runStep($step, $slug, $input, $output);
            if (Command::SUCCESS !== $exitCode) {
                $io->error(\sprintf('"%s" failed: tenant "%s" exists, run the steps left by hand with --tenant=%s.', $step['command'], $slug, $slug));

                return $exitCode;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $step
     */
    private function runStep(array $step, string $slug, InputInterface $input, OutputInterface $output): int
    {
        $application = $this->getApplication() ?? throw new \LogicException('tenant:create runs in an application.');
        // tenant:member:add names its tenant in an argument, and runs as the landlord.
        if ('tenant:member:add' !== $step['command']) {
            $step['--tenant'] = $slug;
        }
        $stepInput = new ArrayInput($step);
        $stepInput->setInteractive($input->isInteractive());

        // doRun(), not run(): the console events select the step's tenant.
        return $application->doRun($stepInput, $output);
    }

    private function userExists(string $email): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM core.app_user WHERE email = ?', [mb_strtolower(trim($email))]);
    }
}
