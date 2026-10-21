<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * A relation field seen from its target: "object.field points at me".
 */
final readonly class RelationReference
{
    public function __construct(
        public ObjectDefinitionDto $object,
        public FieldDefinitionDto $field,
        public RelationSettings $settings,
    ) {
    }

    /**
     * The name of the inverse (one-to-many) side, shown on the target.
     */
    public function inverseLabel(): string
    {
        return $this->settings->inverseLabel ?? \sprintf('%s (%s)', $this->object->labelPlural, $this->field->label);
    }
}
