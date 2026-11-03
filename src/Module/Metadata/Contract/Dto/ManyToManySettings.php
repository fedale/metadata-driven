<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * The settings of a "many_to_many" field, read the same way by every module.
 *
 * The field links a record to any number of records of the target object. It has no column:
 * the links live in a table of their own (see FieldDefinitionDto::storesColumn()). The target
 * sees the inverse side under inverseName, read-only.
 */
final readonly class ManyToManySettings
{
    public const string FIELD_TYPE = 'many_to_many';

    /**
     * @param positive-int|null $max how many records may be linked; null for no limit
     */
    public function __construct(
        public string $target,
        public ManyToManyOnDelete $onDelete = ManyToManyOnDelete::Unlink,
        public ?string $inverseName = null,
        public ?string $inverseLabel = null,
        public ?int $max = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return ['target' => '', 'onDelete' => ManyToManyOnDelete::Unlink->value, 'inverseName' => null, 'inverseLabel' => null, 'max' => null];
    }

    /**
     * Shape checks only: whether the target and the inverse name exist is checked by Metadata.
     *
     * @param array<string, mixed> $settings settings merged with defaults()
     *
     * @return list<string> errors
     */
    public static function validate(array $settings): array
    {
        $errors = [];
        if (!\is_string($settings['target'] ?? null) || '' === $settings['target']) {
            $errors[] = 'Setting "target" must be the name of the related object.';
        }
        if (!\is_string($settings['onDelete'] ?? null) || null === ManyToManyOnDelete::tryFrom($settings['onDelete'])) {
            $errors[] = \sprintf('Setting "onDelete" must be one of: %s.', implode(', ', array_column(ManyToManyOnDelete::cases(), 'value')));
        }
        foreach (['inverseName', 'inverseLabel'] as $key) {
            $value = $settings[$key] ?? null;
            if (null !== $value && (!\is_string($value) || '' === trim($value))) {
                $errors[] = \sprintf('Setting "%s" must be a non-empty string or null.', $key);
            }
        }
        $max = $settings['max'] ?? null;
        if (null !== $max && (!\is_int($max) || $max < 1)) {
            $errors[] = 'Setting "max" must be an integer of at least 1, or null for no limit.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $settings valid settings
     */
    public static function fromSettings(array $settings): self
    {
        $settings += self::defaults();
        $target = $settings['target'];
        $onDelete = \is_string($settings['onDelete']) ? ManyToManyOnDelete::tryFrom($settings['onDelete']) : null;
        $inverseName = $settings['inverseName'];
        $inverseLabel = $settings['inverseLabel'];
        $max = $settings['max'];

        if (!\is_string($target) || '' === $target || null === $onDelete
            || (null !== $inverseName && !\is_string($inverseName))
            || (null !== $inverseLabel && !\is_string($inverseLabel))
            || (null !== $max && (!\is_int($max) || $max < 1))) {
            throw new \InvalidArgumentException(implode(' ', self::validate($settings)));
        }

        return new self($target, $onDelete, $inverseName, $inverseLabel, $max);
    }
}
