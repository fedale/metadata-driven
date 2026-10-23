<?php

declare(strict_types=1);

namespace App\Module\FieldType\Domain\Type;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\SettingDefinition;
use App\Module\FieldType\Contract\Dto\SettingKind;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\SecretHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A secret, validated as typed and stored as a one-way hash. It is never read back: an empty
 * value on update keeps the stored hash.
 */
final class PasswordFieldType extends AbstractFieldType
{
    private const int MAX_MIN_LENGTH = 128;
    // The limit of Symfony's password hashers.
    private const int MAX_LENGTH = 4096;

    public function __construct(
        private readonly SecretHasherInterface $hasher,
    ) {
    }

    public function name(): string
    {
        return 'password';
    }

    public function label(): string
    {
        return 'Password';
    }

    public function description(): string
    {
        return 'A secret stored as a hash, with strength rules and an optional confirmation.';
    }

    public function settingDefinitions(): array
    {
        return [
            new SettingDefinition('minLength', SettingKind::Integer, 'Minimum length', null, true, ['min' => 1, 'max' => self::MAX_MIN_LENGTH]),
            new SettingDefinition('requireMixedCase', SettingKind::Boolean, 'Upper and lower case letters'),
            new SettingDefinition('requireDigit', SettingKind::Boolean, 'At least one digit'),
            new SettingDefinition('requireSymbol', SettingKind::Boolean, 'At least one symbol', 'Any character that is neither a letter nor a digit.'),
            new SettingDefinition('confirm', SettingKind::Boolean, 'Ask twice', 'The form asks for the password a second time and checks both match; the second one is not stored.'),
        ];
    }

    public function defaultSettings(): array
    {
        return ['minLength' => 8, 'requireMixedCase' => false, 'requireDigit' => false, 'requireSymbol' => false, 'confirm' => false];
    }

    protected function validateKnownSettings(array $settings): array
    {
        $errors = [];
        if (!self::isIntBetween($settings['minLength'], 1, self::MAX_MIN_LENGTH)) {
            $errors[] = \sprintf('Setting "minLength" must be an integer between 1 and %d.', self::MAX_MIN_LENGTH);
        }
        foreach (['requireMixedCase', 'requireDigit', 'requireSymbol', 'confirm'] as $flag) {
            if (!\is_bool($settings[$flag])) {
                $errors[] = \sprintf('Setting "%s" must be a boolean.', $flag);
            }
        }

        return $errors;
    }

    public function columnSpec(array $settings): ColumnSpec
    {
        return new ColumnSpec(ColumnType::String, length: 255);
    }

    public function denormalize(mixed $value, array $settings): ?string
    {
        // Spaces are part of a password: not trimmed, only a blank value is empty.
        return self::toNullableString($value, trim: false);
    }

    public function constraints(array $settings): array
    {
        // max() only narrows the type: validateSettings() already guarantees minLength >= 1.
        $constraints = [new Assert\Length(min: max(1, self::intSetting($settings, 'minLength')), max: self::MAX_LENGTH)];
        if (self::boolSetting($settings, 'requireMixedCase')) {
            $constraints[] = new Assert\Regex('/\p{Lu}/u', 'The password must contain an upper case letter.');
            $constraints[] = new Assert\Regex('/\p{Ll}/u', 'The password must contain a lower case letter.');
        }
        if (self::boolSetting($settings, 'requireDigit')) {
            $constraints[] = new Assert\Regex('/\d/u', 'The password must contain a digit.');
        }
        if (self::boolSetting($settings, 'requireSymbol')) {
            $constraints[] = new Assert\Regex('/[^\p{L}\p{N}]/u', 'The password must contain a symbol.');
        }

        return $constraints;
    }

    public function widget(array $settings): Widget
    {
        return new Widget(WidgetKind::Password, ['confirm' => self::boolSetting($settings, 'confirm')]);
    }

    public function isWriteOnly(): bool
    {
        return true;
    }

    public function toStorage(mixed $value, array $settings): ?string
    {
        return \is_string($value) ? $this->hasher->hash($value) : null;
    }
}
