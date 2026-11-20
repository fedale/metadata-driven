<?php

declare(strict_types=1);

namespace App\Module\Package\Application\Source;

use App\Module\Package\Application\Upgrade\Snapshot;
use App\Module\Package\Domain\Exception\PackageSourceFailed;
use App\Module\Package\Domain\Repository\PackageInstallationRepository;
use App\Shared\Message\Message;
use Fedale\PackageFormat\Constraint;
use Fedale\PackageFormat\InvalidPackage;
use Fedale\PackageFormat\PackageDirectory;

/**
 * Opens the package a command names: a directory, or "vendor/name" ("vendor/name@1.2.0" for a
 * given version) found in the indexes and downloaded. An upgrade looks first in the index its
 * package was installed from.
 *
 * A package named from an index comes with the packages it requires that are not installed
 * (openWithRequirements()): each the newest version its constraint allows, from the indexes
 * too. Their requirements are read from the archives' manifests, not from the index: an
 * index's signature covers the archive's checksum, not the requirements it lists.
 */
final class PackageLocator
{
    /**
     * The most packages one install may bring along: every archive is capped in size, the chain
     * must be too, or an index could make one install download without end.
     */
    public const int MAX_REQUIREMENTS = 20;

    private const string NAMED = '~^(?<name>[a-z][a-z0-9-]*/[a-z][a-z0-9-]*)(?:@(?<version>(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)))?$~';

    public function __construct(
        private readonly PackageDirectory $directories,
        private readonly PackageSources $sources,
        private readonly PackageInstallationRepository $installations,
    ) {
    }

    /**
     * @param string|null $repo the index to look in, by name; null for all of them
     *
     * @throws PackageSourceFailed
     * @throws InvalidPackage
     */
    public function open(string $package, ?string $repo = null): OpenedPackage
    {
        if (is_dir($package)) {
            return new OpenedPackage($this->directories->read($package), realpath($package) ?: $package, null);
        }
        if (1 !== preg_match(self::NAMED, $package, $match)) {
            throw new PackageSourceFailed(new Message('package.source.not_a_package', '"%package%" is neither a directory nor a package name (vendor/name, or vendor/name@1.0.0).', ['package' => $package]));
        }
        $installed = $this->installations->findByName($match['name']);

        return $this->download($this->sources->locate($match['name'], '' === ($match['version'] ?? '') ? null : $match['version'], $repo, $installed?->getSource()));
    }

    /**
     * The package open() opens, preceded by the packages it requires that this instance does
     * not have, each before the packages requiring it: the order to install them in. A
     * package opened from a directory comes alone: there is no index to take its requirements
     * from, and the install names those missing.
     *
     * A requirement installed already is left to the install, which checks its version: it is
     * never upgraded on the side. A package required twice is opened once, and must fit both
     * constraints.
     *
     * @return non-empty-list<OpenedPackage> close() each
     *
     * @throws PackageSourceFailed
     * @throws InvalidPackage
     */
    public function openWithRequirements(string $package, ?string $repo = null): array
    {
        $opened = $this->open($package, $repo);
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
     * Opens the requirements of a package not installed yet, theirs before them.
     *
     * @param non-empty-list<string>       $path  the packages requiring it, down to it
     * @param array<string, OpenedPackage> $chain the requirements opened so far, by name, in install order
     *
     * @throws PackageSourceFailed
     * @throws InvalidPackage
     */
    private function requirements(OpenedPackage $requiring, array $path, array &$chain): void
    {
        $requires = $requiring->package->manifest->spec['requires'] ?? null;
        foreach (Snapshot::requirementsOf(\is_array($requires) ? $requires : []) as $name => $text) {
            if (\in_array($name, $path, true)) {
                throw new PackageSourceFailed(new Message('package.source.require_cycle', 'These packages require each other: %cycle%. None of them can be installed.', ['cycle' => self::cycle([...$path, $name])]));
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
                    throw new PackageSourceFailed(new Message('package.source.requirement_mismatch', '%package% requires %required% %constraint%, and %required% %version% comes with another package it requires: install the one that fits both first.', ['package' => $requiring->package->name(), 'required' => $name, 'constraint' => (string) $constraint, 'version' => $chain[$name]->package->version()]));
                }
                continue;
            }
            // Those opened, and those on the path whose requirements are being opened.
            if (\count($chain) + \count($path) - 1 >= self::MAX_REQUIREMENTS) {
                throw new PackageSourceFailed(new Message('package.source.too_many_requirements', '%package% requires more than %max% packages this instance does not have: install some of them first.', ['package' => $path[0], 'max' => self::MAX_REQUIREMENTS]));
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
     * Downloads a located version and reads it; refused when the archive holds another package
     * or version than the index lists.
     *
     * @throws PackageSourceFailed
     * @throws InvalidPackage
     */
    private function download(LocatedPackage $located): OpenedPackage
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

            throw new PackageSourceFailed(new Message('package.source.archive_mismatch', 'The archive listed as %name% %version% holds %held_name% %held_version%.', ['name' => $located->entry->name, 'version' => $located->entry->version, 'held_name' => $package->name(), 'held_version' => $package->version()]));
        }

        return new OpenedPackage($package, $located->index, $directory);
    }

    /**
     * "a requires b requires a", each link a message of its own so that the words translate.
     *
     * @param non-empty-list<string> $names
     */
    private static function cycle(array $names): string|Message
    {
        $cycle = array_shift($names);
        foreach ($names as $name) {
            $cycle = new Message('package.source.requires', '%package% requires %required%', ['package' => $cycle, 'required' => $name]);
        }

        return $cycle;
    }
}
