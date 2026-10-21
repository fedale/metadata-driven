<?php

declare(strict_types=1);

namespace App\Module\Search\Application;

use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use App\Module\Search\Contract\SearchConfigurationInterface;
use App\Module\Search\Domain\Handler\FreeTextMultiFieldHandler;

final class SearchConfiguration implements SearchConfigurationInterface
{
    public const string DEFAULT_HANDLER = FreeTextMultiFieldHandler::NAME;

    /** Widgets of the fields searched when an object configures none. */
    private const array TEXT_WIDGETS = [WidgetKind::Text, WidgetKind::Textarea, WidgetKind::Email];

    public function __construct(
        private readonly SearchHandlerRegistry $handlers,
        private readonly FieldTypeRegistryInterface $fieldTypes,
    ) {
    }

    public function fieldsFor(ObjectDefinitionDto $object): array
    {
        if (null !== $object->searchFields) {
            return $object->searchFields;
        }

        $fields = [];
        foreach ($object->fields as $name => $field) {
            if (\in_array($this->fieldTypes->get($field->type)->widget($field->settings)->kind, self::TEXT_WIDGETS, true)) {
                $fields[] = $name;
            }
        }

        return $fields;
    }

    public function clauses(ObjectDefinitionDto $object, string $term, ?array $fields = null): array
    {
        $fields ??= $this->fieldsFor($object);
        if ([] === $fields) {
            return [];
        }

        return $this->handlers->get($object->searchHandler ?? self::DEFAULT_HANDLER)->clauses($term, $fields);
    }
}
