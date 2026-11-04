<?php

declare(strict_types=1);

namespace App\Module\FieldType\Domain\Type;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\FileUpload;
use App\Module\FieldType\Contract\Dto\SettingDefinition;
use App\Module\FieldType\Contract\Dto\SettingKind;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\Exception\InvalidFieldValue;
use App\Module\FieldType\Domain\Constraint\NewFile;
use App\Module\File\Contract\FileStorageInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A document or an image. The column holds the id of a file of core.file; the bytes are in the
 * file storage. A value is either a new file (a FileUpload, checked and then stored) or the id
 * of the file already there (kept as it is).
 */
final class FileFieldType extends AbstractFieldType
{
    // Decimal megabytes, as the File constraint counts them with binaryFormat: false.
    private const int BYTES_PER_MB = 1_000_000;
    private const int MAX_SIZE_MB = 100;
    private const string MIME_PATTERN = '~^[a-z0-9][a-z0-9!#$&^_.+-]*/(\*|[a-z0-9][a-z0-9!#$&^_.+-]*)$~';

    public function __construct(
        private readonly FileStorageInterface $files,
    ) {
    }

    public function name(): string
    {
        return 'file';
    }

    public function label(): string
    {
        return 'File';
    }

    public function description(): string
    {
        return 'A document or an image, uploaded with the record.';
    }

    public function settingDefinitions(): array
    {
        return [
            new SettingDefinition('accept', SettingKind::String, 'Accepted types', 'MIME types separated by commas, e.g. application/pdf, image/*. The type is read from the content of the file, not from its name. Empty: any type.'),
            new SettingDefinition('maxSize', SettingKind::Integer, 'Maximum size (MB)', \sprintf('From 1 to %d MB. The upload limits of the server apply too.', self::MAX_SIZE_MB), true, ['min' => 1, 'max' => self::MAX_SIZE_MB]),
            new SettingDefinition('image', SettingKind::Boolean, 'Images only', 'Accepts images only (JPEG, PNG, GIF, WebP...), checked to be real images.'),
        ];
    }

    public function defaultSettings(): array
    {
        return ['accept' => null, 'maxSize' => 10, 'image' => false];
    }

    protected function validateKnownSettings(array $settings): array
    {
        $errors = [];
        $accept = $settings['accept'];
        if (null !== $accept && !\is_string($accept)) {
            $errors[] = 'Setting "accept" must be a string.';
        } elseif (\is_string($accept)) {
            foreach (self::types($accept) as $type) {
                if (1 !== preg_match(self::MIME_PATTERN, $type)) {
                    $errors[] = \sprintf('Setting "accept": "%s" is not a MIME type (e.g. application/pdf, image/*).', $type);
                }
            }
        }
        if (!self::isIntBetween($settings['maxSize'], 1, self::MAX_SIZE_MB)) {
            $errors[] = \sprintf('Setting "maxSize" must be an integer between 1 and %d (MB).', self::MAX_SIZE_MB);
        }
        if (!\is_bool($settings['image'])) {
            $errors[] = 'Setting "image" must be a boolean.';
        } elseif ($settings['image'] && \is_string($accept)) {
            foreach (self::types($accept) as $type) {
                if (!str_starts_with($type, 'image/')) {
                    $errors[] = \sprintf('Setting "accept": "%s" is not an image type, and the field accepts images only.', $type);
                }
            }
        }

        return $errors;
    }

    public function columnSpec(array $settings): ColumnSpec
    {
        return new ColumnSpec(ColumnType::Guid, referencesCoreTable: 'file');
    }

    public function denormalize(mixed $value, array $settings): FileUpload|string|null
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if ($value instanceof FileUpload) {
            return $value;
        }
        if (!\is_string($value)) {
            throw new InvalidFieldValue('This value should be a file.');
        }

        // The id of a file stored before: the value the record already holds.
        return null !== $this->files->find($value) ? $value : throw new InvalidFieldValue('This file does not exist.');
    }

    public function constraints(array $settings): array
    {
        // max() only narrows the type: validateSettings() already guarantees maxSize >= 1.
        $maxSize = max(1, self::intSetting($settings, 'maxSize')) * self::BYTES_PER_MB;
        $types = self::types(self::nullableStringSetting($settings, 'accept') ?? '');
        $rule = self::boolSetting($settings, 'image')
            ? new Assert\Image(maxSize: $maxSize, binaryFormat: false, mimeTypes: [] !== $types ? $types : ['image/*'])
            : new Assert\File(maxSize: $maxSize, binaryFormat: false, mimeTypes: [] !== $types ? $types : null);

        return [new NewFile([$rule])];
    }

    public function widget(array $settings): Widget
    {
        $types = self::types(self::nullableStringSetting($settings, 'accept') ?? '');
        $image = self::boolSetting($settings, 'image');

        return new Widget(WidgetKind::File, [
            // A hint for the browser's file picker; the server checks the content.
            'accept' => implode(',', [] !== $types ? $types : ($image ? ['image/*'] : [])),
            'maxSize' => self::intSetting($settings, 'maxSize'),
            'image' => $image,
        ]);
    }

    public function supportsUnique(): bool
    {
        return false;
    }

    public function isSortable(): bool
    {
        return false;
    }

    public function toStorage(mixed $value, array $settings): ?string
    {
        if ($value instanceof FileUpload) {
            return $this->files->store($value->path, $value->originalName)->id;
        }

        return \is_string($value) ? $value : null;
    }

    /**
     * @return list<non-empty-string> the MIME types of an "accept" setting, lowercase
     */
    private static function types(string $accept): array
    {
        $types = [];
        foreach (explode(',', $accept) as $type) {
            $type = mb_strtolower(trim($type));
            if ('' !== $type) {
                $types[] = $type;
            }
        }

        return $types;
    }
}
