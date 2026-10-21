<?php

declare(strict_types=1);

namespace App\Module\FieldType\Domain\Type;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\SettingDefinition;
use App\Module\FieldType\Contract\Dto\SettingKind;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\Exception\InvalidFieldValue;
use App\Module\Metadata\Contract\Dto\OnDelete;
use App\Module\Metadata\Contract\Dto\RelationSettings;
use Symfony\Component\Uid\Uuid;

/**
 * Many-to-one link: stores the id of a record of the target object.
 *
 * Whether that record exists is checked by validation, and enforced by a foreign key.
 */
final class RelationFieldType extends AbstractFieldType
{
    public function name(): string
    {
        return RelationSettings::FIELD_TYPE;
    }

    public function label(): string
    {
        return 'Relation';
    }

    public function description(): string
    {
        return 'A link to a record of another object (many-to-one).';
    }

    public function settingDefinitions(): array
    {
        return [
            new SettingDefinition('target', SettingKind::ObjectName, 'Related object', null, true),
            new SettingDefinition('onDelete', SettingKind::Choice, 'When the related record is deleted', null, true, ['choices' => [
                OnDelete::SetNull->value => 'Clear this field',
                OnDelete::Restrict->value => 'Refuse the deletion',
                OnDelete::Cascade->value => 'Delete this record too',
            ]]),
            new SettingDefinition('inverseName', SettingKind::String, 'Inverse name', 'Machine name of the list of these records on the related object, e.g. "pets".'),
            new SettingDefinition('inverseLabel', SettingKind::String, 'Inverse label', 'Title of that list, e.g. "Pets".'),
        ];
    }

    public function defaultSettings(): array
    {
        return RelationSettings::defaults();
    }

    protected function validateKnownSettings(array $settings): array
    {
        return RelationSettings::validate($settings);
    }

    public function columnSpec(array $settings): ColumnSpec
    {
        return new ColumnSpec(ColumnType::Guid);
    }

    public function denormalize(mixed $value, array $settings): ?string
    {
        $id = self::toNullableString($value);
        if (null === $id) {
            return null;
        }
        if (!Uuid::isValid($id)) {
            throw new InvalidFieldValue('This value is not a valid record reference.');
        }

        return strtolower($id);
    }

    public function widget(array $settings): Widget
    {
        return new Widget(WidgetKind::Relation, ['target' => RelationSettings::fromSettings($settings)->target]);
    }
}
