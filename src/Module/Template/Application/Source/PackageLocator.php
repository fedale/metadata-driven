<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Source;

use App\Module\Template\Application\Upgrade\Snapshot;
use App\Module\Template\Domain\Exception\TemplateSourceFailed;
use App\Module\Template\Domain\Repository\TemplateInstallationRepository;
use Fedale\TemplateFormat\Constraint;
use Fedale\TemplateFormat\InvalidPackage;
use Fedale\TemplateFormat\PackageDirectory;

/**
 * Opens the template a command names: a directory, or "vendor/name" ("vendor/name@1.2.0" for a
 * given version) found in the indexes and downloaded. An upgrade looks first in the index its
 * template was installed from.
 *
 * A template named from an index comes with the templates it requires that are not installed
 * (openWithRequirements()): each the newest version its constraint allows, from the indexes
 * too. Their requirements are read from the archives' manifests, not from the index: an
 * index's signature covers the archive's checksum, not the requirements it lists.
 */
final class PackageLocator
{
    /**
     * The most templates one install may bring along: every archive is capped in size, the chain
     * must be too, or an index could make one install download without end.
     */
    public const int MAX_REQUIREMENTS = 20;

    private const string NAMED = '~^(?<name>[a-z][a-z0-9-]*/[a-z][a-z0-9-]*)(?:@(?<version>(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)))?$~';

    public function __construct(
        private readonly PackageDirectory $directories,
        private readonly TemplateSources $sources,
        private readonly TemplateInstallationRepository $installations,
    ) {
    }

    /**
     * @param string|null $repo the index to look in, by name; null for all of them
     *
     * @throws TemplateSourceFailed
     * @throws InvalidPackage
     */
    public function open(string $template, ?string $repo = null): OpenedPackage
    {
        if (is_dir($template)) {
            return new OpenedPackage($this->directories->read($template), realpath($template) ?: $template, null);
        }
        if (1 !== preg_match(self::NAMED, $template, $match)) {
            throw new TemplateSourceFailed(\sprintf('"%s" is neither a directory nor a template name (%s, or %s@%s).', $template, 'vendor/name', 'vendor/name', '1.0.0'));
        }
        $installed = $this->installations->findByName($match['name']);

        return $this->download($this->sources->locate($match['name'], '' === ($match['version'] ?? '') ? null : $match['version'], $repo, $installed?->getSource()));
    }

    /**
     * The template open() opens, preceded by the templates it requires that this instance does
     * not have, each before the templates requiring it: the order to install them in. A
     * template opened from a directory comes alone: there is no index to take its requirements
     * from, and the install names those missing.
     *
     * A requirement installed already is left to the install, which checks its version: it is
     * never upgraded on the side. A template required twice is opened once, and must fit both
     * constraints.
     *
     * @return non-empty-list<OpenedPackage> close() each
     *
     * @throws TemplateSourceFailed
     * @throws InvalidPackage
     */
    public function openWithRequirements(string $template, ?string $repo = null): array
    {
        $opened = $this->open($template, $repo);
        if (null === $opened->download) {
            return [$opened];
        }
        $chain = [];
        try {
            $this->requirements($opened, [$opened->package->name()], $chain);
        } catch (\Throwable $e) {
            foreach ([$opened, ...$chain] as $open) {
                $this->close($open);
            }

            throw $e;
        }

        return [...array_values($chain), $opened];
    }

    /**
     * Removes what open() downloaded.
     */
    public function close(OpenedPackage $opened): void
    {
        if (null !== $opened->download) {
            $this->sources->discard($opened->download);
        }
    }

    /**
     * Opens the requirements of a template not installed yet, theirs before them.
     *
     * @param non-empty-list<string>       $path  the templates requiring it, down to it
     * @param array<string, OpenedPackage> $chain the requirements opened so far, by name, in install order
     *
     * @throws TemplateSourceFailed
     * @throws InvalidPackage
     */
    private function requirements(OpenedPackage $requiring, array $path, array &$chain): void
    {
        $requires = $requiring->package->manifest->spec['requires'] ?? null;
        foreach (Snapshot::requirementsOf(\is_array($requires) ? $requires : []) as $name => $text) {
            if (\in_array($name, $path, true)) {
                throw new TemplateSourceFailed(\sprintf('These templates require each other: %s. None of them can be installed.', implode(' requires ', [...$path, $name])));
            }
            try {
                $constraint = Constraint::parse($text);
            } catch (\InvalidArgumentException) {
                // The install's check reports it, with the place in the manifest.
                continue;
            }
            if (null !== $this->installations->findByName($name)) {
                continue;
            }
            if (isset($chain[$name])) {
                if (!$constraint->allows($chain[$name]->package->version())) {
                    throw new TemplateSourceFailed(\sprintf('%s requires %s %s, and %s %s comes with another template it requires: install the one that fits both first.', $requiring->package->name(), $name, $constraint, $name, $chain[$name]->package->version()));
                }
                continue;
            }
            // Those opened, and those on the path whose requirements are being opened.
            if (\count($chain) + \count($path) - 1 >= self::MAX_REQUIREMENTS) {
                throw new TemplateSourceFailed(\sprintf('%s requires more than %d templates this instance does not have: install some of them first.', $path[0], self::MAX_REQUIREMENTS));
            }
            $opened = $this->download($this->sources->locateFitting($name, $constraint, $requiring->source));
            try {
                $this->requirements($opened, [...$path, $name], $chain);
            } catch (\Throwable $e) {
                $this->close($opened);

                throw $e;
            }
            $chain[$name] = $opened;
        }
    }

    /**
     * Downloads a located version and reads it; refused when the archive holds another template
     * or version than the index lists.
     *
     * @throws TemplateSourceFailed
     * @throws InvalidPackage
     */
    private function download(LocatedTemplate $located): OpenedPackage
    {
        $directory = $this->sources->download($located);
        try {
            $package = $this->directories->read($directory);
        } catch (InvalidPackage $e) {
            $this->sources->discard($directory);

            throw $e;
        }
        if ($package->name() !== $located->entry->name || $package->version() !== $located->entry->version) {
            $this->sources->discard($directory);

            throw new TemplateSourceFailed(\sprintf('The archive listed as %s %s holds %s %s.', $located->entry->name, $located->entry->version, $package->name(), $package->version()));
        }

        return new OpenedPackage($package, $located->index, $directory);
    }
}
