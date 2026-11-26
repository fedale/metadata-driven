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
use App\Shared\Message\Message;

/**
 * A time of day, without a date: an opening hour, a slot. Its value is a DateTimeImmutable
 * dated 1970-01-01, as the time a date-time holds.
 */
final class TimeFieldType extends AbstractFieldType
{
    private const array FORMATS = ['!H:i', '!H:i:s'];

    public function name(): string
    {
        return 'time';
    }

    public function label(): string
    {
        return 'Time';
    }

    public function description(): string
    {
        return 'A time of day, without a date.';
    }

    public function settingDefinitions(): array
    {
        // Not fixed: the column is time(0) either way.
        return [
            new SettingDefinition('seconds', SettingKind::Boolean, 'With seconds', 'The input asks for the seconds too.'),
        ];
    }

    public function defaultSettings(): array
    {
        return ['seconds' => false];
    }

    protected function validateKnownSettings(array $settings): array
    {
        if (!\is_bool($settings['seconds'])) {
            return [new Message('field_type.setting_not_boolean', 'Setting "%setting%" must be a boolean.', ['setting' => 'seconds'])];
        }

        return [];
    }

    public function columnSpec(array $settings): ColumnSpec
    {
        return new ColumnSpec(ColumnType::Time);
    }

    /**
     * "14:30", "14:30:15", or the time of a date-time. Without "seconds" the seconds are zero.
     */
    public function denormalize(mixed $value, array $settings): ?\DateTimeImmutable
    {
        $seconds = self::boolSetting($settings, 'seconds');
        if ($value instanceof \DateTimeInterface) {
            return self::time((int) $value->format('G'), (int) $value->format('i'), $seconds ? (int) $value->format('s') : 0);
        }
        $string = self::toNullableString($value);
        if (null === $string) {
            return null;
        }
        foreach (self::FORMATS as $format) {
            $time = \DateTimeImmutable::createFromFormat($format, $string);
            $errors = \DateTimeImmutable::getLastErrors();
            // Refuses 25:00, which PHP would roll over to the next day.
            if (false !== $time && (false === $errors || 0 === $errors['warning_count'] + $errors['error_count'])) {
                return self::time((int) $time->format('G'), (int) $time->format('i'), $seconds ? (int) $time->format('s') : 0);
            }
        }

        throw new InvalidFieldValue(new Message('field_type.value.not_time', 'This value is not a valid time.'));
    }

    public function widget(array $settings): Widget
    {
        return new Widget(WidgetKind::Time, ['seconds' => self::boolSetting($settings, 'seconds')]);
    }

    private static function time(int $hours, int $minutes, int $seconds): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('1970-01-01 00:00:00'))->setTime($hours, $minutes, $seconds);
    }
}
