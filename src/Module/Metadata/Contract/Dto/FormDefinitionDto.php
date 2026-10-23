<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * Read-only snapshot of a composite form: one form writing a record and the records related
 * to it (one-to-one and one-to-many sections, nested), in one transaction.
 */
final readonly class FormDefinitionDto
{
    /**
     * @param string $source the definition as written (YAML), comments included
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $label,
        public FormNodeDto $root,
        public string $source,
    ) {
    }
}
