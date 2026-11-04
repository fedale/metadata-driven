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
use App\Module\Metadata\Contract\Dto\ManyToManyOnDelete;
use App\Module\Metadata\Contract\Dto\ManyToManySettings;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Many-to-many link: a record holds any number of records of the target object.
 *
 * The field has no column of its own: its links live in a table of their own (one row per
 * linked record), built by the Schema module. Its value is the list of the linked ids.
 */
final class ManyToManyFieldType extends AbstractFieldType
{
    public function name(): string
    {
        return ManyToManySettings::FIELD_TYPE;
    }

    public function label(): string
    {
        return 'Many-to-many';
    }

    public function description(): string
    {
        return 'Links to any number of records of another object (many-to-many).';
    }

    public function settingDefinitions(): array
    {
        return [
            new SettingDefinition('target', SettingKind::ObjectName, 'Related object', null, true, fixed: true),
            new SettingDefinition('onDelete', SettingKind::Choice, 'When a linked record is deleted', null, true, ['choices' => [
                ManyToManyOnDelete::Unlink->value => 'Remove the link',
                ManyToManyOnDelete::Restrict->value => 'Refuse the deletion',
            ]], fixed: true),
            new SettingDefinition('inverseName', SettingKind::String, 'Inverse name', 'Machine name of the list of these records on the related object, e.g. "contacts".', fixed: true),
            new SettingDefinition('inverseLabel', SettingKind::String, 'Inverse label', 'Title of that list, e.g. "Contacts".'),
            new SettingDefinition('max', SettingKind::Integer, 'Maximum', 'How many records may be linked. Empty: no limit.', options: ['min' => 1]),
            new SettingDefinition('through', SettingKind::ObjectName, 'Through (junction object)', 'Empty: a link table of its own. Otherwise the object whose records are the links, e.g. "user_tag".', fixed: true),
            new SettingDefinition('throughSource', SettingKind::String, 'Junction field to this object', 'With "through": its relation field pointing at these records, e.g. "user".', fixed: true),
            new SettingDefinition('throughTarget', SettingKind::String, 'Junction field to the related object', 'With "through": its relation field pointing at the related records, e.g. "tag".', fixed: true),
        ];
    }

    public function defaultSettings(): array
    {
        return ManyToManySettings::defaults();
    }

    protected function validateKnownSettings(array $settings): array
    {
        return ManyToManySettings::validate($settings);
    }

    /**
     * There is no column: this is the type of one linked id, the column of the link table that
     * holds it.
     */
    public function columnSpec(array $settings): ColumnSpec
    {
        return new ColumnSpec(ColumnType::Guid);
    }

    /**
     * A list of record ids (UUIDs, any case); a single id counts as a list of one. Duplicates
     * are dropped, the order is not kept. No id at all is null, so that "required" means "at
     * least one".
     *
     * @return list<string>|null
     */
    public function denormalize(mixed $value, array $settings): ?array
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_array($value)) {
            $value = [$value];
        }

        $ids = [];
        foreach ($value as $item) {
            $id = self::toNullableString($item);
            if (null === $id) {
                continue;
            }
            if (!Uuid::isValid($id)) {
                throw new InvalidFieldValue('This value is not a valid record reference.');
            }
            $ids[strtolower($id)] = true;
        }

        return [] === $ids ? null : array_keys($ids);
    }

    public function constraints(array $settings): array
    {
        $max = ManyToManySettings::fromSettings($settings)->max;

        return null === $max ? [] : [new Assert\Count(max: $max, maxMessage: 'Choose at most {{ limit }} records.')];
    }

    public function widget(array $settings): Widget
    {
        return new Widget(WidgetKind::ManyToMany, ['target' => ManyToManySettings::fromSettings($settings)->target]);
    }

    public function isSortable(): bool
    {
        return false;
    }
}
