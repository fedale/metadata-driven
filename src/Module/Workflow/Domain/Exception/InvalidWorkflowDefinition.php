<?php

declare(strict_types=1);

namespace App\Module\Workflow\Domain\Exception;

/**
 * A definition that cannot be activated: every problem, located by its path in the definition
 * ("steps.0.branches.1.steps.0.values.note"; "" for the definition as a whole).
 */
final class InvalidWorkflowDefinition extends \DomainException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct('The workflow is invalid: '.implode('; ', array_map(
            static fn (string $path, array $messages): string => ('' === $path ? '' : $path.': ').implode(' ', $messages),
            array_keys($errors),
            $errors,
        )));
    }
}
