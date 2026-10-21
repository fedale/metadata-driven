<?php

declare(strict_types=1);

namespace App\Module\Record\Contract\Exception;

final class RecordValidationFailed extends \DomainException
{
    /** Key used for errors that are not about a single field. */
    public const string GLOBAL_ERRORS = '';

    /**
     * @param array<string, list<string>> $errors error messages keyed by field name
     */
    public function __construct(
        public readonly string $objectName,
        public readonly array $errors,
    ) {
        parent::__construct(\sprintf('The "%s" record is invalid: %s', $objectName, implode(', ', array_map(
            static fn (string $field, array $messages): string => \sprintf('%s: %s', '' === $field ? 'record' : $field, implode(' ', $messages)),
            array_keys($errors),
            $errors,
        ))));
    }
}
