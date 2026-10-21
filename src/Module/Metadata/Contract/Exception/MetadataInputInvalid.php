<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Exception;

/**
 * The input of a metadata change breaks a rule; the errors are keyed by input name
 * (e.g. "name", "labelPlural") so that a form can show them next to its fields.
 */
final class MetadataInputInvalid extends MetadataException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct(implode(' ', array_map(
            static fn (string $input, array $messages): string => \sprintf('%s: %s', $input, implode(' ', $messages)),
            array_keys($errors),
            $errors,
        )));
    }
}
