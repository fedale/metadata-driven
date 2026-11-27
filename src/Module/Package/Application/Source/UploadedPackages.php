<?php

declare(strict_types=1);

namespace App\Module\Package\Application\Source;

use App\Module\Package\Application\ProblemMessages;
use App\Module\Package\Domain\Exception\PackageSourceFailed;
use App\Shared\Message\Message;
use App\Shared\Workspace\CurrentWorkspace;
use Fedale\PackageFormat\Archive;
use Fedale\PackageFormat\InvalidPackage;
use Fedale\PackageFormat\PackageDirectory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Package archives uploaded from the admin, unpacked until they are installed or given up:
 * var/package-uploads/<workspace>/<token>, the token random, named by the pages in between (the
 * trial install, then the confirmation). A workspace never sees another's uploads, and a page
 * names an upload, never a path.
 *
 * The limits of a download apply (app.package.max_download_mb, app.package.max_unpacked_mb), and
 * the archive is checked as a package before it is kept. An upload left over is removed a day
 * later, at the next upload.
 */
final class UploadedPackages
{
    private const string TOKEN = '/^[a-f0-9]{32}$/';
    private const int KEEP_SECONDS = 86_400;

    public function __construct(
        private readonly Archive $archive,
        private readonly PackageDirectory $directories,
        private readonly CurrentWorkspace $workspace,
        #[Autowire('%kernel.project_dir%/var/package-uploads')]
        private readonly string $root,
        #[Autowire(param: 'app.package.max_download_mb')]
        private readonly int $maxUploadMb,
        #[Autowire(param: 'app.package.max_unpacked_mb')]
        private readonly int $maxUnpackedMb,
    ) {
    }

    /**
     * Unpacks an uploaded archive and checks that it reads as a package.
     *
     * @return string the token naming it
     *
     * @throws PackageSourceFailed
     */
    public function store(string $archive, string $originalName): string
    {
        $this->collectGarbage();
        if (!is_file($archive) || filesize($archive) > $this->maxUploadMb * 1_000_000) {
            throw new PackageSourceFailed(new Message('package.upload.too_large', 'The archive is over %max% MB: it was not unpacked.', ['max' => $this->maxUploadMb]));
        }
        $token = bin2hex(random_bytes(16));
        $directory = $this->directory($token);
        try {
            $this->archive->unpack($archive, $directory, $this->maxUnpackedMb * 1_000_000);
            $this->directories->read($directory);
        } catch (InvalidPackage $e) {
            $this->discard($token);

            throw new PackageSourceFailed(new Message('package.upload.invalid', '%file%: %problem%', ['file' => $originalName, 'problem' => new Message('package.install.invalid', 'The package is invalid: %problems%', ['problems' => ProblemMessages::list($e)])]), 0, $e);
        }
        file_put_contents($directory.'.name', $originalName);

        return $token;
    }

    /**
     * The package an upload holds, as an install takes it: its source says where it came from.
     *
     * @throws InvalidPackage
     */
    public function open(string $token): ?OpenedPackage
    {
        if (1 !== preg_match(self::TOKEN, $token) || !is_dir($this->directory($token))) {
            return null;
        }
        $name = (string) @file_get_contents($this->directory($token).'.name');

        return new OpenedPackage($this->directories->read($this->directory($token)), 'upload: '.('' !== $name ? $name : $token), null);
    }

    public function discard(string $token): void
    {
        if (1 === preg_match(self::TOKEN, $token)) {
            (new Filesystem())->remove([$this->directory($token), $this->directory($token).'.name']);
        }
    }

    private function directory(string $token): string
    {
        // The workspace's data schema names it on disk: unique, and a plain identifier.
        return $this->root.'/'.$this->workspace->dataSchema().'/'.$token;
    }

    private function collectGarbage(): void
    {
        $directory = $this->root.'/'.$this->workspace->dataSchema();
        foreach (glob($directory.'/*', \GLOB_ONLYDIR) ?: [] as $upload) {
            if (filemtime($upload) < time() - self::KEEP_SECONDS) {
                $this->discard(basename($upload));
            }
        }
    }
}
