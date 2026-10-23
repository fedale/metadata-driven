<?php

declare(strict_types=1);

namespace App\Module\Metadata\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * A composite form: one form over a root object and the objects related to it.
 *
 * The definition is kept twice: as written (YAML, comments included, shown back to the
 * editor) and compiled (checked against the objects, normalized, read by the pages).
 */
class FormDefinition
{
    private string $name;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $definition compiled: see FormDefinitionCompiler
     */
    public function __construct(
        private Uuid $id,
        string $name,
        private string $label,
        private string $source,
        private array $definition,
    ) {
        $this->name = MachineName::assert($name);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function redefine(string $label, string $source, array $definition): void
    {
        $this->label = $label;
        $this->source = $source;
        $this->definition = $definition;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefinition(): array
    {
        return $this->definition;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
