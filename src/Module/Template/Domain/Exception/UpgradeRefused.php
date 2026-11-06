<?php

declare(strict_types=1);

namespace App\Module\Template\Domain\Exception;

use App\Module\Template\Contract\Exception\TemplateException;

/**
 * An upgrade that changes nothing: every reason found, each naming the document and the item
 * it comes from.
 */
final class UpgradeRefused extends TemplateException
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public readonly array $reasons,
        public readonly bool $conflicts = false,
    ) {
        parent::__construct(1 === \count($reasons)
            ? \sprintf('The template cannot be upgraded: %s', $reasons[0])
            : \sprintf('The template cannot be upgraded (%d reasons): %s', \count($reasons), implode('; ', $reasons)));
    }

    public static function notInstalled(string $name): self
    {
        return new self([\sprintf('%s is not installed: install it first.', $name)]);
    }

    public static function notNewer(string $name, string $installed, string $version): self
    {
        return new self([\sprintf('%s %s is installed: %s is not newer.', $name, $installed, $version)]);
    }

    /**
     * @param list<string> $conflicts
     */
    public static function conflicts(array $conflicts): self
    {
        return new self($conflicts, conflicts: true);
    }

    public static function at(string $path, string $item, string $message): self
    {
        return new self([\sprintf('%s: %s: %s', $path, $item, $message)]);
    }
}
