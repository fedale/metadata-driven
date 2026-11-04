<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * The settings of a "file" field holding several files ("multiple": true), read the same way
 * by every module.
 *
 * The field has no column: its files are rows of a link table of its own, in the order the user
 * chose (see FieldDefinitionDto::storesColumn()).
 */
final readonly class FileListSettings
{
    public const string FIELD_TYPE = 'file';

    /**
     * @param positive-int|null $max how many files the field may hold; null for no limit
     */
    public function __construct(
        public ?int $max = null,
    ) {
    }

    /**
     * @param array<string, mixed> $settings valid settings of a "file" field
     *
     * @return self|null null when the field holds one file
     */
    public static function fromSettings(array $settings): ?self
    {
        if (true !== ($settings['multiple'] ?? false)) {
            return null;
        }
        $max = $settings['max'] ?? null;

        return new self(\is_int($max) && $max >= 1 ? $max : null);
    }
}
