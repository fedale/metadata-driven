<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Selects the workspace a command works for: --workspace=<slug>, an option every command gets, or
 * the default workspace (app.workspace.default) when the option is left out.
 *
 * Only the application's commands and fedale/* bundles' (RBAC, settings) get the default. The
 * others work for no workspace, as the system: the migrations and the other Doctrine and DBAL
 * commands work across workspaces, and a default workspace would hide the others' rows from them
 * where row-level security applies; the framework's (cache:clear) must run before the
 * migrations have made core.workspace what this code expects. So do the workspace:* commands.
 * --workspace still selects one for any command.
 */
#[AsEventListener(event: ConsoleEvents::COMMAND)]
final class ConsoleWorkspaceListener
{
    public const string OPTION = 'workspace';

    /** Namespaces of the classes of the commands that get the default workspace. */
    private const array WORKSPACE_COMMANDS = ['App\\', 'Fedale\\'];

    public function __construct(
        private readonly WorkspaceRegistry $registry,
        private readonly WorkspaceSwitcher $switcher,
        #[Autowire('%app.workspace.default%')]
        private readonly string $default,
    ) {
    }

    public function __invoke(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        if (null === $command) {
            return;
        }
        // A global option, as --env: declared on the application, so that every command's full
        // definition gets it (Command::run() rebuilds that from the application's). The input
        // was bound before this event, unknown options left for listeners to declare: bind it
        // again.
        $application = $command->getApplication();
        if (null !== $application && !$application->getDefinition()->hasOption(self::OPTION)) {
            $application->getDefinition()->addOption(new InputOption(self::OPTION, null, InputOption::VALUE_REQUIRED, 'The slug of the workspace to work for (default: the default workspace, for the application\'s commands)'));
        }
        $command->mergeApplicationDefinition();
        $input = $event->getInput();
        $input->bind($command->getDefinition());
        if (!$input->hasOption(self::OPTION)) {
            return;
        }

        $slug = $input->getOption(self::OPTION);
        if (!\is_string($slug) || '' === $slug) {
            $slug = $this->worksForAWorkspace($command) ? $this->default : '';
        }
        if ('' === $slug) {
            $this->switcher->system();

            return;
        }
        $workspace = $this->registry->bySlug($slug);
        if (null === $workspace || !$workspace->active) {
            throw new RuntimeException(\sprintf(null === $workspace ? 'There is no workspace "%s".' : 'Workspace "%s" is suspended.', $slug));
        }
        $this->switcher->switchTo($workspace);
    }

    private function worksForAWorkspace(Command $command): bool
    {
        if (str_starts_with((string) $command->getName(), 'workspace:')) {
            return false;
        }
        $class = self::commandClass($command);
        foreach (self::WORKSPACE_COMMANDS as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The class the command's code is in. Commands registered lazily are wrapped; an
     * #[AsCommand] class with __invoke() is the code of a plain Command.
     */
    private static function commandClass(Command $command): string
    {
        if ($command instanceof LazyCommand) {
            $command = $command->getCommand();
        }
        $code = $command->getCode();
        if ($code instanceof \Closure) {
            return (new \ReflectionFunction($code))->getClosureScopeClass()?->getName() ?? $command::class;
        }
        if (\is_object($code)) {
            return $code::class;
        }
        if (\is_array($code) && \is_object($code[0])) {
            return $code[0]::class;
        }

        return $command::class;
    }
}
