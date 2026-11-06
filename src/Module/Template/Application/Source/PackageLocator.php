<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Source;

use App\Module\Template\Domain\Exception\TemplateSourceFailed;
use App\Module\Template\Domain\Repository\TemplateInstallationRepository;
use App\TemplateFormat\InvalidPackage;
use App\TemplateFormat\PackageDirectory;

/**
 * Opens the template a command names: a directory, or "vendor/name" ("vendor/name@1.2.0" for a
 * given version) found in the indexes and downloaded. An upgrade looks first in the index its
 * template was installed from.
 */
final class PackageLocator
{
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
        $located = $this->sources->locate($match['name'], '' === ($match['version'] ?? '') ? null : $match['version'], $repo, $installed?->getSource());
        $directory = $this->sources->download($located->entry);
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

    /**
     * Removes what open() downloaded.
     */
    public function close(OpenedPackage $opened): void
    {
        if (null !== $opened->download) {
            $this->sources->discard($opened->download);
        }
    }
}
