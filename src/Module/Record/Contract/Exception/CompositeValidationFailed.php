<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Exception;

/**
 * A composite form was refused; nothing was written.
 *
 * The errors are keyed by the path of the input they concern, as the form names it:
 * "values.username", "service.terms", "sections.profile.values.city",
 * "sections.profile.sections.phones.2.values.number", or "sections.phones" for a section as a
 * whole (too few rows). "" holds the errors about the whole form.
 */
final class CompositeValidationFailed extends \DomainException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct('The form is invalid: '.implode(', ', array_map(
            static fn (string $path, array $messages): string => \sprintf('%s: %s', '' === $path ? 'form' : $path, implode(' ', $messages)),
            array_keys($errors),
            $errors,
        )));
    }
}
