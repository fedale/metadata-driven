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
use App\Module\Metadata\Contract\Dto\FileListSettings;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A document or an image. The column holds the id of a file of core.file; the bytes are in the
 * file storage. A value is either a new file (a FileUpload, checked and then stored) or the id
 * of the file already there (kept as it is).
 *
 * With "multiple" the field holds an ordered list of files instead, in a link table of its own
 * (FileListSettings): its value is a list whose items are a FileUpload or an id, and an empty
 * list is null, so that "required" means at least one file. Whether an id is one the record
 * holds is for the Record module to check: the type does not know the record.
 */
final class FileFieldType extends AbstractFieldType
{
    // Decimal megabytes, as the File constraint counts them with binaryFormat: false.
    private const int BYTES_PER_MB = 1_000_000;
    private const int MAX_SIZE_MB = 100;
    private const int MAX_FILES = 100;
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
            new SettingDefinition('multiple', SettingKind::Boolean, 'Several files', 'The field holds a list of files, in the order chosen, instead of one file. It cannot change once the field exists.', fixed: true),
            new SettingDefinition('max', SettingKind::Integer, 'Most files', \sprintf('With several files: how many the field may hold, from 1 to %d. Empty: no limit.', self::MAX_FILES), options: ['min' => 1, 'max' => self::MAX_FILES]),
        ];
    }

    public function defaultSettings(): array
    {
        return ['accept' => null, 'maxSize' => 10, 'image' => false, 'multiple' => false, 'max' => null];
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
        if (!\is_bool($settings['multiple'])) {
            $errors[] = 'Setting "multiple" must be a boolean.';
        }
        $max = $settings['max'];
        if (null !== $max && !self::isIntBetween($max, 1, self::MAX_FILES)) {
            $errors[] = \sprintf('Setting "max" must be an integer between 1 and %d, or empty for no limit.', self::MAX_FILES);
        } elseif (null !== $max && true !== $settings['multiple']) {
            $errors[] = 'Setting "max" applies to a field holding several files ("multiple") only.';
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

    /**
     * @return FileUpload|string|non-empty-list<FileUpload|string>|null
     */
    public function denormalize(mixed $value, array $settings): FileUpload|string|array|null
    {
        if (null !== FileListSettings::fromSettings($settings)) {
            return self::denormalizeList($value);
        }
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

        $list = FileListSettings::fromSettings($settings);
        if (null === $list) {
            return [new NewFile([$rule])];
        }

        // Each upload of the list is checked as the one file of a field would be.
        $constraints = [new Assert\All([new NewFile([$rule])])];
        if (null !== $list->max) {
            $constraints[] = new Assert\Count(max: $list->max, maxMessage: 'Keep at most {{ limit }} files.');
        }

        return $constraints;
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
            'multiple' => self::boolSetting($settings, 'multiple'),
            'max' => self::nullableIntSetting($settings, 'max'),
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

    /**
     * @return string|list<string>|null the id of the file, or the ids of the list in order (an
     *                                  empty list for none)
     */
    public function toStorage(mixed $value, array $settings): string|array|null
    {
        if (null !== FileListSettings::fromSettings($settings)) {
            $ids = [];
            foreach (\is_array($value) ? $value : [] as $item) {
                $id = $this->storedId($item);
                if (null !== $id) {
                    $ids[] = $id;
                }
            }

            return $ids;
        }

        return $this->storedId($value);
    }

    private function storedId(mixed $value): ?string
    {
        if ($value instanceof FileUpload) {
            return $this->files->store($value->path, $value->originalName)->id;
        }

        return \is_string($value) ? $value : null;
    }

    /**
     * The items of a list in order, the ids lowercase and once each; an empty list is null.
     *
     * @return non-empty-list<FileUpload|string>|null
     */
    private static function denormalizeList(mixed $value): ?array
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $items = [];
        $ids = [];
        foreach (\is_array($value) ? $value : [$value] as $item) {
            if ($item instanceof FileUpload) {
                $items[] = $item;
                continue;
            }
            if (null === $item || '' === $item) {
                continue;
            }
            if (!\is_string($item) || !Uuid::isValid($item)) {
                throw new InvalidFieldValue('This value should be a list of files.');
            }
            $id = strtolower($item);
            if (!isset($ids[$id])) {
                $ids[$id] = true;
                $items[] = $id;
            }
        }

        return [] === $items ? null : $items;
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
