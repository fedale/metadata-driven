<?php

declare(strict_types=1);

namespace App\Module\FieldType\Domain\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Applies its constraints to a new file (a FileUpload) only. A file field also holds the id of
 * a file stored before, which was checked when it was uploaded: Symfony's File constraint would
 * take that id for a path.
 */
final class NewFile extends Constraint
{
    /**
     * @param list<Constraint> $constraints
     */
    public function __construct(
        public readonly array $constraints,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }

    public function validatedBy(): string
    {
        // A string, not ::class: the Domain layer may not depend on Infrastructure. Both the
        // container and Validation::createValidator() resolve a validator by its class name.
        return 'App\Module\FieldType\Infrastructure\Validator\NewFileValidator';
    }
}
