<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

use App\Shared\Message\Message;
use App\Shared\Message\MessageList;

/**
 * The settings of a "relation" field (many-to-one), read the same way by every module.
 *
 * The field stores the id of a record of the target object. The target sees the
 * inverse side (one-to-many) under inverseName, read-only.
 *
 * A relation may depend on another relation field of the same object (a cascade: region,
 * province, municipality): dependsOn names that field, dependsVia the relation field of the
 * target pointing at the same object. Only the target's records whose dependsVia is the record
 * chosen in dependsOn can then be picked.
 */
final readonly class RelationSettings
{
    public const string FIELD_TYPE = 'relation';

    public function __construct(
        public string $target,
        public OnDelete $onDelete = OnDelete::SetNull,
        public ?string $inverseName = null,
        public ?string $inverseLabel = null,
        public ?string $dependsOn = null,
        public ?string $dependsVia = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return ['target' => '', 'onDelete' => OnDelete::SetNull->value, 'inverseName' => null, 'inverseLabel' => null, 'dependsOn' => null, 'dependsVia' => null];
    }

    /**
     * Shape checks only: whether the target and the inverse name exist is checked by Metadata.
     *
     * @param array<string, mixed> $settings settings merged with defaults()
     *
     * @return list<Message> errors
     */
    public static function validate(array $settings): array
    {
        $errors = [];
        if (!\is_string($settings['target'] ?? null) || '' === $settings['target']) {
            $errors[] = new Message('field_type.target_invalid', 'Setting "target" must be the name of the related object.');
        }
        if (!\is_string($settings['onDelete'] ?? null) || null === OnDelete::tryFrom($settings['onDelete'])) {
            $errors[] = new Message('field_type.on_delete_invalid', 'Setting "onDelete" must be one of: %values%.', ['values' => implode(', ', array_column(OnDelete::cases(), 'value'))]);
        }
        foreach (['inverseName', 'inverseLabel', 'dependsOn', 'dependsVia'] as $key) {
            $value = $settings[$key] ?? null;
            if (null !== $value && (!\is_string($value) || '' === trim($value))) {
                $errors[] = new Message('field_type.setting_not_string_or_null', 'Setting "%setting%" must be a non-empty string or null.', ['setting' => $key]);
            }
        }

        if ((null === ($settings['dependsOn'] ?? null)) !== (null === ($settings['dependsVia'] ?? null))) {
            $errors[] = new Message('field_type.relation.depends_on_without_via', 'Settings "dependsOn" and "dependsVia" go together: give both or neither.');
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
        $onDelete = \is_string($settings['onDelete']) ? OnDelete::tryFrom($settings['onDelete']) : null;
        $inverseName = $settings['inverseName'];
        $inverseLabel = $settings['inverseLabel'];
        $dependsOn = $settings['dependsOn'];
        $dependsVia = $settings['dependsVia'];

        if (!\is_string($target) || '' === $target || null === $onDelete
            || (null !== $inverseName && !\is_string($inverseName))
            || (null !== $inverseLabel && !\is_string($inverseLabel))
            || (null !== $dependsOn && !\is_string($dependsOn))
            || (null !== $dependsVia && !\is_string($dependsVia))) {
            throw new \InvalidArgumentException((string) new MessageList(self::validate($settings), ' '));
        }

        return new self($target, $onDelete, $inverseName, $inverseLabel, $dependsOn, $dependsVia);
    }
}
