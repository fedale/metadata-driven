<?php

declare(strict_types=1);

namespace App\TemplateFormat;

/**
 * A package that cannot be read or does not follow the format; every problem found is listed.
 */
final class InvalidPackage extends \DomainException
{
    /**
     * @param list<Problem> $problems
     */
    public function __construct(
        public readonly array $problems,
    ) {
        parent::__construct(1 === \count($problems)
            ? \sprintf('The template is invalid: %s', $problems[0])
            : \sprintf('The template is invalid (%d problems): %s', \count($problems), implode('; ', $problems)));
    }
}
