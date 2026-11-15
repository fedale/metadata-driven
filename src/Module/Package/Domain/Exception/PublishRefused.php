<?php

declare(strict_types=1);

namespace App\Module\Package\Domain\Exception;

use App\Module\Package\Contract\Exception\PackageException;

/**
 * A package version the catalog would refuse: every reason found is listed, as the catalog's
 * pull request check would list them.
 */
final class PublishRefused extends PackageException
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public readonly array $reasons,
    ) {
        parent::__construct(1 === \count($reasons)
            ? \sprintf('The package cannot be published: %s', $reasons[0])
            : \sprintf('The package cannot be published (%d reasons): %s', \count($reasons), implode('; ', $reasons)));
    }
}
