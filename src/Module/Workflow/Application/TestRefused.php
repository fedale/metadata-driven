<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application;

/**
 * A test that cannot run: no record chosen ("choose"), or one of an object the tester may not
 * read ("cannot_read").
 */
final class TestRefused extends \DomainException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $object,
    ) {
        parent::__construct(\sprintf('The test cannot run (%s, %s).', $reason, $object));
    }
}
