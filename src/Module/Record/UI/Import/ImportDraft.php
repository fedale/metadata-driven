<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Import;

/**
 * A CSV file on its way through the import wizard: the file uploaded, and what the user said of
 * it so far. Kept in the session (ImportDrafts), the file in the drafts directory.
 */
final class ImportDraft
{
    /**
     * @param array<string, string|null> $mapping column of the file => the field it fills; null: ignored
     * @param array<string, string>      $matches relation field => the unique field of its target its cells hold
     *                                            (a field left out holds record ids)
     * @param string|null                $checked the fingerprint of the settings the last check passed with
     */
    public function __construct(
        public readonly string $id,
        public readonly string $object,
        public readonly string $originalName,
        public readonly string $separator,
        public array $mapping = [],
        public ?string $key = null,
        public array $matches = [],
        public ?string $checked = null,
    ) {
    }

    /**
     * What a check depends on: a change of the columns, the key or the matches needs a new one.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->mapping, $this->key, $this->matches], \JSON_THROW_ON_ERROR));
    }

    public function isChecked(): bool
    {
        return $this->checked === $this->fingerprint();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'object' => $this->object,
            'originalName' => $this->originalName,
            'separator' => $this->separator,
            'mapping' => $this->mapping,
            'key' => $this->key,
            'matches' => $this->matches,
            'checked' => $this->checked,
        ];
    }

    /**
     * @param array<mixed> $data as toArray() wrote it
     */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $name): string => \is_string($data[$name] ?? null) ? $data[$name] : '';
        $mapping = \is_array($data['mapping'] ?? null) ? $data['mapping'] : [];
        $matches = \is_array($data['matches'] ?? null) ? $data['matches'] : [];

        return new self(
            $string('id'),
            $string('object'),
            $string('originalName'),
            $string('separator'),
            array_map(static fn (mixed $field): ?string => \is_string($field) ? $field : null, array_combine(array_map(strval(...), array_keys($mapping)), $mapping)),
            \is_string($data['key'] ?? null) ? $data['key'] : null,
            array_filter(array_combine(array_map(strval(...), array_keys($matches)), $matches), is_string(...)),
            \is_string($data['checked'] ?? null) ? $data['checked'] : null,
        );
    }
}
