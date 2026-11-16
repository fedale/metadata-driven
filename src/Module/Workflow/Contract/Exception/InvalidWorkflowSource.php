<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract\Exception;

/**
 * A workflow a package brings that the app refuses: its name, or its definition's structure.
 */
final class InvalidWorkflowSource extends \DomainException
{
    /**
     * @param array<string, list<string>> $errors by path in the definition ("steps.0.type"), "name"
     *                                            for the name
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct(implode(' ', array_map(
            static fn (string $path, array $messages): string => ('' === $path ? '' : $path.': ').implode(' ', $messages),
            array_keys($errors),
            $errors,
        )));
    }
}
