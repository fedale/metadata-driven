<?php

declare(strict_types=1);

namespace App\TemplateFormat\FieldTypes;

/**
 * A setting holding the id of another item of the template: an object, or a field of some
 * object (the field's own, its target's, or its junction object's).
 */
final readonly class SettingReference
{
    public function __construct(
        public string $setting,
        public ReferenceKind $kind,
        public string $id,
        /** For a field: whose field it is. */
        public ?FieldOwner $of = null,
    ) {
    }
}
