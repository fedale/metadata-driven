<?php

declare(strict_types=1);

namespace App\Module\FieldType\Infrastructure\Validator;

use App\Module\FieldType\Contract\Dto\FileUpload;
use App\Module\FieldType\Domain\Constraint\NewFile;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class NewFileValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NewFile) {
            throw new UnexpectedTypeException($constraint, NewFile::class);
        }
        if (!$value instanceof FileUpload) {
            return;
        }

        // In the same context: the violations land on the field, as if the constraints were its own.
        $this->context->getValidator()->inContext($this->context)->validate($value, $constraint->constraints);
    }
}
