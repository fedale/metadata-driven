<?php

declare(strict_types=1);

namespace App\Module\Template\UI\Console;

use App\Module\Template\Application\RequiredTemplates;
use Fedale\TemplateFormat\InvalidPackage;
use Fedale\TemplateFormat\PackageChecker;
use Fedale\TemplateFormat\PackageDirectory;
use Fedale\TemplateFormat\Problem;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'metadata:template:validate', description: 'Check that a template follows the format, without installing it')]
final class ValidateTemplateConsoleCommand
{
    public function __construct(
        private readonly PackageDirectory $directories,
        private readonly PackageChecker $checker,
        private readonly RequiredTemplates $required,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The directory of the template')] string $directory,
    ): int {
        try {
            $package = $this->directories->read($directory);
        } catch (InvalidPackage $e) {
            return self::invalid($io, $e->problems);
        }
        // The templates it requires are checked against as installed here, as the install does.
        $problems = $this->checker->check($package, $this->required->installed($package));
        if ([] !== $problems) {
            return self::invalid($io, $problems);
        }

        $io->success(\sprintf('Template %s %s follows the format (%d documents).', $package->name(), $package->version(), \count($package->documents)));
        $io->note('Only the structure is checked: whether its forms and views fit its objects is checked when it is installed.');

        return Command::SUCCESS;
    }

    /**
     * @param list<Problem> $problems
     */
    private static function invalid(SymfonyStyle $io, array $problems): int
    {
        $io->error(\sprintf('The template is invalid: %d problem(s).', \count($problems)));
        $io->listing(array_map(strval(...), $problems));

        return Command::FAILURE;
    }
}
