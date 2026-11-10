<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Publish;

use App\Module\Template\Application\Upgrade\Snapshot;
use App\Module\Template\Domain\Exception\PublishRefused;
use Fedale\TemplateFormat\Constraint;
use Fedale\TemplateFormat\Format;
use Fedale\TemplateFormat\InvalidPackage;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\PackageChecker;
use Fedale\TemplateFormat\PackageDirectory;
use Fedale\TemplateFormat\Problem;
use Fedale\TemplateFormat\Versions\ItemStates;
use Fedale\TemplateFormat\Versions\VersionCheck;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Prepares a template version for the central catalog (fedale/metadata-driven-templates): its
 * source directory copied into a checkout of the catalog, at templates/<vendor>/<name>/<version>/,
 * ready to be committed and proposed in a pull request.
 *
 * Before copying, it runs what the catalog's pull request check would refuse, against the
 * checkout as it is (pull it first): the format, a registered vendor, a template not removed, a
 * version higher than the published ones whose number fits its changes, the size, no links;
 * the templates it requires published there, in a version fitting each constraint and not
 * yanked (it is checked against the highest one, as an installation takes it), with no cycle.
 * Who owns the vendor is the check's to tell: it knows the pull request's author.
 *
 * Nothing touches git: committing, pushing and opening the pull request stay with the author.
 */
final readonly class TemplatePublisher
{
    public const string TEMPLATES = 'templates';
    public const string VENDORS = 'vendors.yaml';
    public const string YANKED = 'yanked.yaml';

    /** The catalog's limit per version (PullRequestCheck::MAX_VERSION_BYTES there). */
    public const int MAX_VERSION_BYTES = 20_000_000;

    public function __construct(
        private PackageDirectory $directories,
        private PackageChecker $checker,
        private ItemStates $states,
    ) {
    }

    /**
     * @param bool $dryRun check everything, copy nothing
     *
     * @throws PublishRefused
     */
    public function publish(string $source, string $catalog, bool $dryRun = false): PublishReport
    {
        $source = rtrim($source, '/');
        $catalog = rtrim($catalog, '/');
        if (!is_dir($catalog.'/'.self::TEMPLATES) || !is_file($catalog.'/'.self::VENDORS)) {
            throw new PublishRefused([\sprintf('%s is not a checkout of the template catalog: it has no %s/ and %s. Clone it first: git clone git@github.com:fedale/metadata-driven-templates.git', $catalog, self::TEMPLATES, self::VENDORS)]);
        }
        [$files, $bytes] = self::files($source);

        try {
            $package = $this->directories->read($source);
        } catch (InvalidPackage $e) {
            throw new PublishRefused(array_map(strval(...), $e->problems));
        }
        $problems = $this->checkAgainstRequirements($catalog, $package);
        if ([] !== $problems) {
            throw new PublishRefused($problems);
        }

        [$vendor] = explode('/', $package->name());
        $directory = self::TEMPLATES.'/'.$package->name().'/'.$package->version();
        $problems = [];
        if (!\in_array($vendor, self::vendors($catalog), true)) {
            $problems[] = \sprintf('The vendor "%s" is not registered in the catalog (%s): add it there in a pull request of its own first.', $vendor, self::VENDORS);
        }
        if (self::isRemoved($catalog, $package->name())) {
            $problems[] = \sprintf('%s is removed from the catalog (%s: all): list it again before publishing a new version.', $package->name(), self::YANKED);
        }
        if ($bytes > self::MAX_VERSION_BYTES) {
            $problems[] = \sprintf('%.1f MB: the catalog takes %d MB per version at most, files included.', $bytes / 1_000_000, self::MAX_VERSION_BYTES / 1_000_000);
        }
        $published = self::published($catalog, $package->name());
        $higher = array_values(array_filter($published, static fn (string $version): bool => version_compare($version, $package->version(), '>=')));
        if ([] !== $higher) {
            $problems[] = \sprintf('%s %s is already in the catalog: the new version must be higher.', $package->name(), end($higher));
        } elseif ([] !== $published) {
            $problems = [...$problems, ...$this->numberProblems($catalog, $package, end($published))];
        }
        if ([] !== $problems) {
            throw new PublishRefused($problems);
        }

        if (!$dryRun) {
            self::copy($source, $catalog.'/'.$directory, $files);
        }

        return new PublishReport($package->name(), $package->version(), $directory, \count($files), $bytes, [] === $published ? null : end($published), $dryRun);
    }

    /**
     * The format's checks against the templates the version requires, as the catalog has them.
     * A requirement it doesn't have is said so first (the references to its items fail with
     * it), rather than as the checker says it ("it must be installed").
     *
     * @return list<string>
     *
     * @throws PublishRefused
     */
    private function checkAgainstRequirements(string $catalog, Package $package): array
    {
        $yanked = self::yanked($catalog);
        $required = [];
        $missing = [];
        $problems = [];
        foreach (self::requires($package) as $name => $constraint) {
            $found = $this->fitting($catalog, $yanked, $name, $constraint);
            if ($found instanceof Package) {
                $required[] = $found;
            } elseif (null === $found) {
                $missing[] = $name;
                $problems[] = \sprintf('%s: spec.requires.%s: The catalog has no version of %s that fits %s and is not yanked: publish it first, in a pull request of its own.', Format::MANIFEST, $name, $name, $constraint);
            }
        }
        $problems = [...$problems, ...array_map(strval(...), array_values(array_filter(
            $this->checker->check($package, $required),
            static fn (Problem $problem): bool => !(Format::MANIFEST === $problem->path && \in_array(substr($problem->at, \strlen('spec.requires.')), $missing, true)),
        )))];
        $cycle = $this->cycle($catalog, $yanked, $package, [$package->name()], []);
        if (null !== $cycle) {
            $problems[] = \sprintf('%s: spec.requires: These templates would require each other: %s. None of them could be installed.', Format::MANIFEST, $cycle);
        }

        return $problems;
    }

    /**
     * The first cycle the version would close, following the requirements' own as published.
     *
     * @param array<string, list<string>|true> $yanked
     * @param non-empty-list<string>           $path
     * @param array<string, true>              $seen
     */
    private function cycle(string $catalog, array $yanked, Package $package, array $path, array $seen): ?string
    {
        foreach (self::requires($package) as $name => $constraint) {
            if (\in_array($name, $path, true)) {
                return implode(' requires ', [...$path, $name]);
            }
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $found = $this->fitting($catalog, $yanked, $name, $constraint);
            $cycle = $found instanceof Package ? $this->cycle($catalog, $yanked, $found, [...$path, $name], $seen) : null;
            if (null !== $cycle) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * The highest version of a template in the catalog its constraint allows and not yanked, as
     * an installation takes it.
     *
     * @param array<string, list<string>|true> $yanked name => its yanked versions, true when removed
     *
     * @return Package|false|null null when none fits; false when the constraint is not one (the
     *                            checker reports it)
     */
    private function fitting(string $catalog, array $yanked, string $name, string $constraint): Package|false|null
    {
        try {
            $parsed = Constraint::parse($constraint);
        } catch (\InvalidArgumentException) {
            return false;
        }
        $removed = true === ($yanked[$name] ?? null);
        $versions = array_filter(
            self::published($catalog, $name),
            static fn (string $version): bool => !$removed && $parsed->allows($version) && !\in_array($version, \is_array($yanked[$name] ?? null) ? $yanked[$name] : [], true),
        );
        if ([] === $versions) {
            return null;
        }
        try {
            return $this->directories->read($catalog.'/'.self::TEMPLATES.'/'.$name.'/'.end($versions));
        } catch (InvalidPackage) {
            return null;
        }
    }

    /**
     * @return array<string, string> the templates a version requires: name => constraint
     */
    private static function requires(Package $package): array
    {
        return Snapshot::requirementsOf(\is_array($package->manifest->spec['requires'] ?? null) ? $package->manifest->spec['requires'] : []);
    }

    /**
     * Whether the version's number fits what it changed against the highest published one: the
     * rules of metadata-driven's upgrades, which the catalog applies too.
     *
     * @return list<string>
     */
    private function numberProblems(string $catalog, Package $package, string $previous): array
    {
        try {
            $before = $this->directories->read($catalog.'/'.self::TEMPLATES.'/'.$package->name().'/'.$previous);
        } catch (InvalidPackage) {
            return [\sprintf('The published version %s cannot be read to compare with it.', $previous)];
        }

        $migrates = false;
        $migrations = $package->manifest->spec['migrations'] ?? null;
        foreach (\is_array($migrations) ? $migrations : [] as $version => $steps) {
            $migrates = $migrates || (version_compare((string) $version, $previous, '>') && version_compare((string) $version, $package->version(), '<=') && [] !== $steps);
        }

        return VersionCheck::problems($this->states->of($before), $this->states->of($package), $previous, $package->version(), $migrates);
    }

    /**
     * Every regular file of the source, and their size; a link or anything else is refused, as
     * the catalog refuses it.
     *
     * @return array{list<string>, int} the paths relative to the source, sorted
     *
     * @throws PublishRefused
     */
    private static function files(string $source): array
    {
        if (!is_dir($source) || is_link($source)) {
            throw new PublishRefused([\sprintf('%s is not a template directory.', $source)]);
        }
        $files = [];
        $bytes = 0;
        $links = [];
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $path = substr($entry->getPathname(), \strlen($source) + 1);
            if ($entry->isLink()) {
                $links[] = \sprintf('%s: A symbolic link: the catalog takes files and directories only.', $path);
            } elseif ($entry->isFile()) {
                $files[] = $path;
                $bytes += $entry->getSize();
            } elseif (!$entry->isDir()) {
                $links[] = \sprintf('%s: Not a file: the catalog takes files and directories only.', $path);
            }
        }
        if ([] !== $links) {
            throw new PublishRefused($links);
        }
        sort($files);

        return [$files, $bytes];
    }

    /**
     * @param list<string> $files
     *
     * @throws PublishRefused
     */
    private static function copy(string $source, string $target, array $files): void
    {
        if (file_exists($target)) {
            throw new PublishRefused([\sprintf('%s already exists.', $target)]);
        }
        foreach ($files as $file) {
            $to = $target.'/'.$file;
            if (!is_dir(\dirname($to)) && !mkdir(\dirname($to), 0o755, true)) {
                throw new PublishRefused([\sprintf('%s: The directory cannot be created.', \dirname($to))]);
            }
            if (!copy($source.'/'.$file, $to)) {
                throw new PublishRefused([\sprintf('%s: The file cannot be copied.', $to)]);
            }
        }
    }

    /**
     * @return list<string> the registered vendors
     *
     * @throws PublishRefused
     */
    private static function vendors(string $catalog): array
    {
        $vendors = self::yaml($catalog.'/'.self::VENDORS);

        return array_map(strval(...), array_keys($vendors));
    }

    /**
     * @throws PublishRefused
     */
    private static function isRemoved(string $catalog, string $name): bool
    {
        return true === (self::yanked($catalog)[$name] ?? null);
    }

    /**
     * @return array<string, list<string>|true> name => its yanked versions, true when removed (all)
     *
     * @throws PublishRefused
     */
    private static function yanked(string $catalog): array
    {
        $file = $catalog.'/'.self::YANKED;
        $yanked = [];
        foreach (is_file($file) ? self::yaml($file) : [] as $name => $versions) {
            $yanked[(string) $name] = 'all' === $versions ? true : array_values(array_map(strval(...), array_filter(\is_array($versions) ? $versions : [], is_scalar(...))));
        }

        return $yanked;
    }

    /**
     * @return list<string> the versions of the template in the catalog, lowest first
     */
    private static function published(string $catalog, string $name): array
    {
        $versions = [];
        foreach (glob($catalog.'/'.self::TEMPLATES.'/'.$name.'/*', \GLOB_ONLYDIR) ?: [] as $directory) {
            $version = basename($directory);
            if (1 === preg_match(Format::VERSION_PATTERN, $version)) {
                $versions[] = $version;
            }
        }
        usort($versions, static fn (string $a, string $b): int => version_compare($a, $b));

        return $versions;
    }

    /**
     * @return array<mixed>
     *
     * @throws PublishRefused
     */
    private static function yaml(string $file): array
    {
        try {
            $data = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new PublishRefused([\sprintf('%s: %s', $file, $e->getMessage())]);
        }

        return \is_array($data) ? $data : [];
    }
}
