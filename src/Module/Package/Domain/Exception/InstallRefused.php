<?php

declare(strict_types=1);

namespace App\Module\Package\Domain\Exception;

use App\Module\Package\Contract\Exception\PackageException;

/**
 * A package that cannot be installed here. Every reason found is listed, each naming the
 * document (and the place in it) it comes from when it has one.
 */
final class InstallRefused extends PackageException
{
    /**
     * @param list<string> $reasons
     */
    private function __construct(
        public readonly array $reasons,
    ) {
        parent::__construct(1 === \count($reasons)
            ? \sprintf('The package cannot be installed: %s', $reasons[0])
            : \sprintf('The package cannot be installed (%d reasons): %s', \count($reasons), implode('; ', $reasons)));
    }

    /**
     * @param list<string> $problems
     */
    public static function invalid(array $problems): self
    {
        return new self($problems);
    }

    public static function alreadyInstalled(string $name, string $version): self
    {
        return new self([\sprintf('%s is already installed, in version %s.', $name, $version)]);
    }

    /**
     * @param list<string> $collisions
     */
    public static function collisions(array $collisions): self
    {
        return new self($collisions);
    }

    /**
     * The same reasons, naming the package refused: one of several installed together.
     */
    public function of(string $package, string $version): self
    {
        return new self(array_map(static fn (string $reason): string => \sprintf('%s %s: %s', $package, $version, $reason), $this->reasons));
    }

    /**
     * A step the app refused while installing: everything done before it is rolled back.
     */
    public static function at(string $path, string $at, string $message): self
    {
        return new self([\sprintf('%s: %s: %s', $path, $at, $message)]);
    }
}
