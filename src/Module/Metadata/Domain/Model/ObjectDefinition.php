<?php

declare(strict_types=1);

namespace App\Module\Metadata\Domain\Model;

use App\Module\Metadata\Contract\Dto\ViewDefinitionDto;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Uid\Uuid;

/**
 * A business object (system or user defined) backed by its own physical table.
 */
class ObjectDefinition
{
    /** Columns every record table gets: field names may not shadow them. */
    public const array SYSTEM_COLUMNS = ['id', 'created_at', 'updated_at', 'created_by', 'deleted_at'];
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
        MachineName::assert($name);
        if (\in_array($name, self::SYSTEM_COLUMNS, true)) {
            throw InvalidMetadataDefinition::reservedFieldName($name);
        }
        if ($this->hasField($name)) {
            throw InvalidMetadataDefinition::duplicateField($this->name, $name);
        }

        $field = new FieldDefinition($id, $this, $name, $type, $label, $isRequired, $isUnique, $settings, $this->fields->count());
        $this->fields->add($field);
        $this->updatedAt = new \DateTimeImmutable();

        return $field;
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
     * flag off the previous one. Every {token} must name a field, once, outside any tag.
     *
     * @param string $template sanitized HTML
     */
    public function saveView(Uuid $id, ViewKind $kind, string $name, string $template, bool $isDefault): ViewDefinition
    {
        MachineName::assert($name);
        $this->assertLayout($name, $template);

        $view = $this->findView($kind, $name);
        if (null === $view) {
            $isDefault = $isDefault || null === $this->defaultView($kind);
            $view = new ViewDefinition($id, $this, $kind, $name, $template, false);
            $this->views->add($view);
        } else {
            $view->change($template);
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

    public function getField(string $name): FieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->getName() === $name) {
                return $field;
            }
        }

        throw InvalidMetadataDefinition::unknownField($this->name, $name);
    }

    public function hasField(string $name): bool
    {
        return $this->fields->exists(static fn (int $key, FieldDefinition $field): bool => $field->getName() === $name);
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
     * @return list<FieldDefinition> ordered by position
     */
    public function getFields(): array
    {
        $fields = $this->fields->getValues();
        usort($fields, static fn (FieldDefinition $a, FieldDefinition $b): int => $a->getPosition() <=> $b->getPosition());

        return $fields;
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
