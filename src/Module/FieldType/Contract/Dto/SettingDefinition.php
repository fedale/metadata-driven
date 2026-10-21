<?php

declare(strict_types=1);

namespace App\Module\FieldType\Contract\Dto;

/**
 * Framework-free description of one setting of a field type, for editors such as the
 * admin UI. The rules themselves stay in FieldTypeInterface::validateSettings().
 */
final readonly class SettingDefinition
{
    /**
     * @param array<string, mixed> $options "choices" (array<string, string>, value => label) for SettingKind::Choice;
     *                                      "min" / "max" (int) for SettingKind::Integer
     */
    public function __construct(
        public string $key,
        public SettingKind $kind,
        public string $label,
        public ?string $help = null,
        public bool $required = false,
        public array $options = [],
    ) {
    }
}
