<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * A node of a composite form: the fields of one object, plus the sections nested in it.
 *
 * The root holds the record the form is about. A section holds the records of another
 * object pointing at the record of its parent node through the relation field "via".
 */
final readonly class FormNodeDto
{
    /**
     * @param string|null           $via           relation field of $object pointing at the parent node's object; null for the root
     * @param list<string>|null     $fields        the fields shown, in order; null for every field (but "via")
     * @param list<ServiceFieldDto> $serviceFields
     * @param list<FormNodeDto>     $sections
     * @param bool                  $inline        the fields side by side, on one line (a phone: prefix, number, type)
     * @param list<string>          $required      fields required by the form although their object does not require them
     * @param string|null           $layout        sanitized HTML placing the fields, service fields and sections with {name} tokens; null: one after another
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $object,
        public ?string $via,
        public FormNodeMode $mode,
        public int $min,
        public ?int $max,
        public ?array $fields,
        public array $serviceFields,
        public array $sections,
        public bool $inline = false,
        public array $required = [],
        public ?string $layout = null,
    ) {
    }

    /**
     * The names the layout places: the rest is shown after it.
     *
     * @return list<string>
     */
    public function placed(): array
    {
        return null === $this->layout ? [] : ViewDefinitionDto::tokens($this->layout);
    }

    /**
     * The fields shown: the configured ones, or every field of the object but "via" (set by
     * the form itself).
     *
     * @return list<string>
     */
    public function fieldNames(ObjectDefinitionDto $object): array
    {
        return $this->fields ?? array_values(array_filter(
            array_keys($object->fields),
            fn (string $name): bool => $name !== $this->via,
        ));
    }

    /**
     * Every object the node and its sections write to.
     *
     * @return list<string>
     */
    public function objects(): array
    {
        $objects = [$this->object];
        foreach ($this->sections as $section) {
            $objects = [...$objects, ...$section->objects()];
        }

        return array_values(array_unique($objects));
    }
}
