<?php

declare(strict_types=1);

namespace App\Module\Metadata\Domain\Model;

use App\Module\Metadata\Contract\Dto\RelationSettings;
use App\Module\Metadata\Contract\Dto\ViewDefinitionDto;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Uid\Uuid;

/**
 * A business object (system or user defined) backed by its own physical table.
 *
 * Its name, and the names of its fields, can change: the table and the columns are renamed
 * with them. An archived object (or field) is out of the application while its table (or
 * column) and its records stay; its name stays taken until it is purged.
 */
class ObjectDefinition
{
    /** Columns every record table gets: field names may not shadow them. */
    public const array SYSTEM_COLUMNS = ['id', 'created_at', 'updated_at', 'created_by', 'deleted_at', 'sort'];
    public const string TABLE_PREFIX = 'c_';

    private string $name;
    private string $tableName;
    private string $labelSingular;
    private string $labelPlural;
    private bool $isActive = true;
    private ?string $searchHandler = null;
    private ?string $labelField = null;
    /** @var list<string>|null */
    private ?array $searchFields = null;
    /** @var list<list<string>>|null the field sets unique together; null for none */
    private ?array $uniqueTogether = null;
    /** @var Collection<int, FieldDefinition> */
    private Collection $fields;
    /** @var Collection<int, ViewDefinition> */
    private Collection $views;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        private Uuid $id,
        string $name,
        string $labelSingular,
        string $labelPlural,
        private ?string $icon = null,
        private bool $isSystem = false,
    ) {
        $this->name = MachineName::assert($name);
        $this->tableName = self::TABLE_PREFIX.$this->name;
        $this->relabel($labelSingular, $labelPlural);
        $this->fields = new ArrayCollection();
        $this->views = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function relabel(string $labelSingular, string $labelPlural): void
    {
        $labelSingular = trim($labelSingular);
        $labelPlural = trim($labelPlural);
        if ('' === $labelSingular || '' === $labelPlural) {
            throw InvalidMetadataDefinition::emptyLabel();
        }

        $this->labelSingular = $labelSingular;
        $this->labelPlural = $labelPlural;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * The table follows the name: the caller renames it in the same transaction.
     */
    public function rename(string $name): void
    {
        $this->name = MachineName::assert($name);
        $this->tableName = self::TABLE_PREFIX.$this->name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function archive(): void
    {
        $this->isActive = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function restore(): void
    {
        $this->isActive = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $settings already validated by the field type
     */
    public function addField(
        Uuid $id,
        string $name,
        string $type,
        string $label,
        bool $isRequired,
        bool $isUnique,
        array $settings,
    ): FieldDefinition {
        $this->assertFieldNameFree($name);

        $field = new FieldDefinition($id, $this, $name, $type, $label, $isRequired, $isUnique, $settings, $this->fields->count());
        $this->fields->add($field);
        $this->updatedAt = new \DateTimeImmutable();

        return $field;
    }

    /**
     * Renames a field and what the object says about it: its label field, its searched fields,
     * the conditions it decides and the relations depending on it. The views, and what other
     * objects say about the field, are the caller's.
     */
    public function renameField(string $fieldName, string $newName): FieldDefinition
    {
        $field = $this->getField($fieldName);
        $this->assertFieldNameFree($newName);
        $field->rename($newName);

        if ($this->labelField === $fieldName) {
            $this->labelField = $newName;
        }
        if (null !== $this->searchFields) {
            $this->searchFields = array_map(static fn (string $name): string => $name === $fieldName ? $newName : $name, $this->searchFields);
        }
        if (null !== $this->uniqueTogether) {
            $this->uniqueTogether = array_map(
                static fn (array $set): array => array_map(static fn (string $name): string => $name === $fieldName ? $newName : $name, $set),
                $this->uniqueTogether,
            );
        }
        foreach ($this->fields as $other) {
            $condition = $other->getCondition();
            if (null !== $condition && $condition['field'] === $fieldName) {
                $other->applyWhen(['field' => $newName, 'values' => $condition['values']]);
            }
            $settings = $other->getSettings();
            if (($settings['dependsOn'] ?? null) === $fieldName) {
                $other->changeSettings(['dependsOn' => $newName] + $settings);
            }
        }
        $this->updatedAt = new \DateTimeImmutable();

        return $field;
    }

    /**
     * Follows the rename of an object in the relation and many-to-many fields pointing at it,
     * and in the many-to-many fields going through it, the archived ones included (their
     * foreign keys point at its table all the same).
     */
    public function retargetRelations(string $oldTarget, string $newTarget): void
    {
        foreach ($this->fields as $field) {
            if ($field->getTarget() === $oldTarget) {
                $field->changeSettings(['target' => $newTarget] + $field->getSettings());
                $this->updatedAt = new \DateTimeImmutable();
            }
            if ($field->getThrough() === $oldTarget) {
                $field->changeSettings(['through' => $newTarget] + $field->getSettings());
                $this->updatedAt = new \DateTimeImmutable();
            }
        }
    }

    /**
     * Follows the rename of a field of another object in the cascades of the relations
     * pointing at it ("dependsVia" names a field of the target), and in the many-to-many
     * fields going through it ("throughSource" and "throughTarget" name its fields).
     */
    public function followTargetFieldRename(string $target, string $oldField, string $newField): void
    {
        foreach ($this->fields as $field) {
            $settings = $field->getSettings();
            if (RelationSettings::FIELD_TYPE === $field->getType() && ($settings['target'] ?? null) === $target && ($settings['dependsVia'] ?? null) === $oldField) {
                $field->changeSettings(['dependsVia' => $newField] + $settings);
                $this->updatedAt = new \DateTimeImmutable();
            }
            if ($field->getThrough() === $target) {
                foreach (['throughSource', 'throughTarget'] as $key) {
                    if (($settings[$key] ?? null) === $oldField) {
                        $settings = [$key => $newField] + $settings;
                        $field->changeSettings($settings);
                        $this->updatedAt = new \DateTimeImmutable();
                    }
                }
            }
        }
    }

    /**
     * Takes a field out of the application; its column stays. It no longer names the records
     * nor is searched. Whether something else still uses it is checked by the caller.
     */
    public function archiveField(string $fieldName): FieldDefinition
    {
        $field = $this->getField($fieldName);
        $field->markArchived(true);
        if ($this->labelField === $fieldName) {
            $this->labelField = null;
        }
        if (null !== $this->searchFields) {
            $fields = array_values(array_diff($this->searchFields, [$fieldName]));
            $this->searchFields = [] === $fields ? null : $fields;
        }
        $this->updatedAt = new \DateTimeImmutable();

        return $field;
    }

    public function restoreField(string $fieldName): FieldDefinition
    {
        $field = $this->getArchivedField($fieldName);
        $field->markArchived(false);
        $this->updatedAt = new \DateTimeImmutable();

        return $field;
    }

    /**
     * Forgets an archived field; the caller drops its column in the same transaction.
     */
    public function removeArchivedField(string $fieldName): void
    {
        $this->fields->removeElement($this->getArchivedField($fieldName));
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @param string|null       $handler null for the default handler
     * @param list<string>|null $fields  null for the default fields (every text-like field)
     */
    public function configureSearch(?string $handler, ?array $fields): void
    {
        if (null !== $fields) {
            foreach ($fields as $field) {
                if (!$this->hasField($field)) {
                    throw InvalidMetadataDefinition::unknownSearchField($this->name, $field);
                }
            }
            $fields = array_values(array_unique($fields));
        }

        $this->searchHandler = $handler;
        $this->searchFields = $fields;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Makes a set of fields unique together: no two live records hold the same values in all
     * of them. Whether the field types allow it is checked by the caller.
     *
     * @param list<string> $fields at least two different fields, in the order of the index
     */
    public function addUniqueTogether(array $fields): void
    {
        foreach ($fields as $field) {
            if (!$this->hasField($field)) {
                throw InvalidMetadataDefinition::unknownField($this->name, $field);
            }
        }
        if (\count($fields) < 2 || \count(array_unique($fields)) !== \count($fields)) {
            throw InvalidMetadataDefinition::invalidUniqueTogether($this->name, $fields, 'name at least two different fields (a single field is made unique when it is created)');
        }
        if (null !== $this->findUniqueTogether($fields)) {
            throw InvalidMetadataDefinition::invalidUniqueTogether($this->name, $fields, 'these fields are already unique together');
        }

        $this->uniqueTogether = [...$this->uniqueTogether ?? [], $fields];
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @param list<string> $fields the fields of the set, in any order
     */
    public function removeUniqueTogether(array $fields): void
    {
        $index = $this->findUniqueTogether($fields) ?? throw InvalidMetadataDefinition::unknownUniqueTogether($this->name, $fields);
        $sets = $this->uniqueTogether ?? [];
        array_splice($sets, $index, 1);
        $this->uniqueTogether = [] === $sets ? null : $sets;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @param string|null $field null for the default (the first text-like field)
     */
    public function useAsLabel(?string $field): void
    {
        if (null !== $field && !$this->hasField($field)) {
            throw InvalidMetadataDefinition::unknownLabelField($this->name, $field);
        }

        $this->labelField = $field;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Creates the view, or replaces the template of the existing one with that kind and name.
     *
     * The first view of a kind becomes its default; making a view the default takes the
     * flag off the previous one. In a form view every {token} must name a field, once, outside
     * any tag; a grid view comes with its definition, compiled (and checked) by the caller.
     *
     * @param string                    $template   sanitized HTML (form), or the YAML source (grid)
     * @param array<string, mixed>|null $definition the compiled grid definition
     */
    public function saveView(Uuid $id, ViewKind $kind, string $name, string $template, bool $isDefault, ?array $definition = null): ViewDefinition
    {
        MachineName::assert($name);
        if (ViewKind::Form === $kind) {
            $this->assertLayout($name, $template);
        } elseif (null === $definition) {
            throw new \LogicException(\sprintf('A %s view needs its compiled definition.', $kind->value));
        }

        $view = $this->findView($kind, $name);
        if (null === $view) {
            $isDefault = $isDefault || null === $this->defaultView($kind);
            $view = new ViewDefinition($id, $this, $kind, $name, $template, false, $definition);
            $this->views->add($view);
        } else {
            $view->change($template, $definition);
        }
        if ($isDefault) {
            foreach ($this->views as $other) {
                if ($other->getKind() === $kind) {
                    $other->markDefault($other === $view);
                }
            }
        }
        $this->updatedAt = new \DateTimeImmutable();

        return $view;
    }

    /**
     * Removing the default leaves the kind without one: its pages fall back to the plain layout.
     */
    public function removeView(ViewKind $kind, string $name): void
    {
        $view = $this->findView($kind, $name) ?? throw InvalidMetadataDefinition::unknownView($this->name, $kind->value, $name);
        $this->views->removeElement($view);
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Makes a field apply only while another field holds one of some values; null values: always.
     *
     * The deciding field must be another field of this object, without a condition of its own,
     * and the field must not decide for another one: no chains. Whether the deciding field's
     * type and the values fit is checked by the caller, who knows the field types.
     *
     * @param list<string> $values
     */
    public function setFieldCondition(string $fieldName, ?string $decidingField, array $values): void
    {
        $field = $this->getField($fieldName);
        if (null === $decidingField) {
            $field->applyWhen(null);
            $this->updatedAt = new \DateTimeImmutable();

            return;
        }

        $deciding = $this->getField($decidingField);
        if ($deciding === $field) {
            throw InvalidMetadataDefinition::invalidCondition($fieldName, 'a field cannot decide for itself');
        }
        if (null !== $deciding->getCondition()) {
            throw InvalidMetadataDefinition::invalidCondition($fieldName, \sprintf('"%s" has a condition of its own: conditions do not chain', $decidingField));
        }
        foreach ($this->fields as $other) {
            if (($other->getCondition()['field'] ?? null) === $fieldName) {
                throw InvalidMetadataDefinition::invalidCondition($fieldName, \sprintf('it decides for "%s": conditions do not chain', $other->getName()));
            }
        }
        $values = array_values(array_unique(array_filter($values, static fn (string $value): bool => '' !== trim($value))));
        if ([] === $values) {
            throw InvalidMetadataDefinition::invalidCondition($fieldName, 'give at least one value');
        }

        $field->applyWhen(['field' => $decidingField, 'values' => $values]);
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Replaces the settings of a field: already validated by its type, and checked by the
     * caller against what the column, the records and the conditions need.
     *
     * @param array<string, mixed> $settings
     */
    public function changeFieldSettings(string $fieldName, array $settings): void
    {
        $this->getField($fieldName)->changeSettings($settings);
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * An active field: an archived one is out of the object until it is restored.
     */
    public function getField(string $name): FieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->getName() === $name && !$field->isArchived()) {
                return $field;
            }
        }

        throw InvalidMetadataDefinition::unknownField($this->name, $name);
    }

    public function getArchivedField(string $name): FieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->getName() === $name && $field->isArchived()) {
                return $field;
            }
        }

        throw InvalidMetadataDefinition::unknownArchivedField($this->name, $name);
    }

    /**
     * Whether the object has this active field.
     */
    public function hasField(string $name): bool
    {
        return $this->fields->exists(static fn (int $key, FieldDefinition $field): bool => $field->getName() === $name && !$field->isArchived());
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getLabelSingular(): string
    {
        return $this->labelSingular;
    }

    public function getLabelPlural(): string
    {
        return $this->labelPlural;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getLabelField(): ?string
    {
        return $this->labelField;
    }

    public function getSearchHandler(): ?string
    {
        return $this->searchHandler;
    }

    /**
     * @return list<string>|null
     */
    public function getSearchFields(): ?array
    {
        return $this->searchFields;
    }

    /**
     * @return list<list<string>> the field sets unique together, each in the order of its index
     */
    public function getUniqueTogether(): array
    {
        return $this->uniqueTogether ?? [];
    }

    /**
     * @return list<FieldDefinition> the active fields, ordered by position
     */
    public function getFields(): array
    {
        return $this->sortedFields(false);
    }

    /**
     * @return list<FieldDefinition> ordered by position
     */
    public function getArchivedFields(): array
    {
        return $this->sortedFields(true);
    }

    /**
     * @return list<ViewDefinition> ordered by kind, then name
     */
    public function getViews(): array
    {
        $views = $this->views->getValues();
        usort($views, static fn (ViewDefinition $a, ViewDefinition $b): int => [$a->getKind()->value, $a->getName()] <=> [$b->getKind()->value, $b->getName()]);

        return $views;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return list<FieldDefinition>
     */
    private function sortedFields(bool $archived): array
    {
        $fields = array_values(array_filter($this->fields->getValues(), static fn (FieldDefinition $field): bool => $field->isArchived() === $archived));
        usort($fields, static fn (FieldDefinition $a, FieldDefinition $b): int => $a->getPosition() <=> $b->getPosition());

        return $fields;
    }

    /**
     * A new name must be valid, no system column, and free: an archived field keeps its name
     * (and its column) until it is purged.
     */
    private function assertFieldNameFree(string $name): void
    {
        MachineName::assert($name);
        if (\in_array($name, self::SYSTEM_COLUMNS, true)) {
            throw InvalidMetadataDefinition::reservedFieldName($name);
        }
        foreach ($this->fields as $field) {
            if ($field->getName() === $name) {
                throw $field->isArchived() ? InvalidMetadataDefinition::archivedFieldNameTaken($this->name, $name) : InvalidMetadataDefinition::duplicateField($this->name, $name);
            }
        }
    }

    /**
     * @param list<string> $fields
     *
     * @return int|null the position of the set holding exactly these fields, in any order
     */
    private function findUniqueTogether(array $fields): ?int
    {
        $wanted = $fields;
        sort($wanted);
        foreach ($this->uniqueTogether ?? [] as $index => $set) {
            sort($set);
            if ($set === $wanted) {
                return $index;
            }
        }

        return null;
    }

    private function findView(ViewKind $kind, string $name): ?ViewDefinition
    {
        foreach ($this->views as $view) {
            if ($view->getKind() === $kind && $view->getName() === $name) {
                return $view;
            }
        }

        return null;
    }

    private function defaultView(ViewKind $kind): ?ViewDefinition
    {
        foreach ($this->views as $view) {
            if ($view->getKind() === $kind && $view->isDefault()) {
                return $view;
            }
        }

        return null;
    }

    private function assertLayout(string $viewName, string $template): void
    {
        $tokens = ViewDefinitionDto::tokens($template);
        foreach ($tokens as $field) {
            if (!$this->hasField($field)) {
                throw InvalidMetadataDefinition::unknownViewField($this->name, $viewName, $field);
            }
        }
        foreach (array_count_values($tokens) as $field => $count) {
            if ($count > 1) {
                throw InvalidMetadataDefinition::duplicateViewToken($viewName, $field);
            }
        }
        // A token inside a tag (an attribute value) would be replaced by markup there.
        if (\count(ViewDefinitionDto::tokens(strip_tags($template))) !== \count($tokens)) {
            throw InvalidMetadataDefinition::viewTokenInsideTag($viewName);
        }
    }
}
