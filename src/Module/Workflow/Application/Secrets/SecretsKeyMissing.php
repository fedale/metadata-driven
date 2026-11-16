<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Secrets;

/**
 * WORKFLOW_SECRETS_KEY is not set, or is no key: no secret is written nor read.
 */
final class SecretsKeyMissing extends \RuntimeException
{
    public static function create(): self
    {
        return new self('The workflows\' secrets need WORKFLOW_SECRETS_KEY: generate one with workflow:secrets:key, and set it where the application reads its environment.');
    }
}
