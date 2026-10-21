<?php

declare(strict_types=1);

namespace App\Module\Metadata\UI\Console;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

trait ReportsViolations
{
    /**
     * Validates the input DTO and prints the violations; returns true when it is valid.
     */
    private function isValid(ValidatorInterface $validator, object $input, SymfonyStyle $io): bool
    {
        $violations = $validator->validate($input);
        foreach ($violations as $violation) {
            $io->error(\sprintf('%s: %s', $violation->getPropertyPath(), (string) $violation->getMessage()));
        }

        return 0 === \count($violations);
    }
}
