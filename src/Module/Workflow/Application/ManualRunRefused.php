<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application;

/**
 * A manual run that may not start: no such workflow, not the user's to run, a record they may
 * not read, or answers that will not do (errors by input name).
 */
final class ManualRunRefused extends \RuntimeException
{
    public const int NOT_FOUND = 1;
    public const int FORBIDDEN = 2;
    public const int INVALID = 3;

    /**
     * @param array<string, string> $errors input name => why its answer will not do
     */
    public function __construct(string $message, int $code, public readonly array $errors = [])
    {
        parent::__construct($message, $code);
    }
}
