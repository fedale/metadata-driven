<?php

declare(strict_types=1);

namespace App\Module\Template\Domain\Exception;

use App\Module\Template\Contract\Exception\TemplateException;

/**
 * A template version the catalog would refuse: every reason found is listed, as the catalog's
 * pull request check would list them.
 */
final class PublishRefused extends TemplateException
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public readonly array $reasons,
    ) {
        parent::__construct(1 === \count($reasons)
            ? \sprintf('The template cannot be published: %s', $reasons[0])
            : \sprintf('The template cannot be published (%d reasons): %s', \count($reasons), implode('; ', $reasons)));
    }
}
