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

final class DateFieldType extends AbstractFieldType
{
    private const string DATE_FORMAT = '!Y-m-d';
    private const array DATETIME_FORMATS = ['!Y-m-d\TH:i', '!Y-m-d\TH:i:s', '!Y-m-d H:i', '!Y-m-d H:i:s', \DateTimeInterface::ATOM];

    public function name(): string
    {
        return 'date';
    }

    public function label(): string
    {
        return 'Date';
    }

    public function description(): string
    {
        return 'A date, optionally with the time.';
    }

    public function settingDefinitions(): array
    {
        return [
            new SettingDefinition('includeTime', SettingKind::Boolean, 'Include the time', fixed: true),
        ];
    }

    public function defaultSettings(): array
    {
        return ['includeTime' => false];
    }

    protected function validateKnownSettings(array $settings): array
    {
        if (!\is_bool($settings['includeTime'])) {
            return ['Setting "includeTime" must be a boolean.'];
        }

        return [];
    }

    public function columnSpec(array $settings): ColumnSpec
    {
        return new ColumnSpec(self::boolSetting($settings, 'includeTime') ? ColumnType::DateTime : ColumnType::Date);
    }

    public function denormalize(mixed $value, array $settings): ?\DateTimeImmutable
    {
        $includeTime = self::boolSetting($settings, 'includeTime');

        if ($value instanceof \DateTimeInterface) {
            $date = \DateTimeImmutable::createFromInterface($value);

            return $includeTime ? $date : $date->setTime(0, 0);
        }

        $string = self::toNullableString($value);
        if (null === $string) {
            return null;
        }

        foreach ($includeTime ? self::DATETIME_FORMATS : [self::DATE_FORMAT] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $string);
            $errors = \DateTimeImmutable::getLastErrors();
            // Rejects overflowing dates such as 2026-02-31, which PHP would silently roll over.
            if (false !== $date && (false === $errors || 0 === $errors['warning_count'] + $errors['error_count'])) {
                return $date;
            }
        }

        throw new InvalidFieldValue($includeTime ? 'This value is not a valid date and time.' : 'This value is not a valid date.');
    }

    public function widget(array $settings): Widget
    {
        return new Widget(self::boolSetting($settings, 'includeTime') ? WidgetKind::DateTime : WidgetKind::Date);
    }
}
