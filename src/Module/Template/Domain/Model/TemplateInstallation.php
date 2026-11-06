<?php

declare(strict_types=1);

namespace App\Module\Template\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * A template installed on this instance: which version, from where, and its documents as they
 * were installed. That snapshot is the base an upgrade compares the new version and the local
 * changes against.
 */
class TemplateInstallation
{
    private ?\DateTimeImmutable $upgradedAt = null;

    /**
     * @param array<string, mixed> $snapshot the documents, by path, each as {kind, id, spec}
     */
    public function __construct(
        private Uuid $id,
        private string $name,
        private string $version,
        /** The directory, archive or index it came from. */
        private string $source,
        private array $snapshot,
        private \DateTimeImmutable $installedAt,
    ) {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }

    public function getInstalledAt(): \DateTimeImmutable
    {
        return $this->installedAt;
    }

    public function getUpgradedAt(): ?\DateTimeImmutable
    {
        return $this->upgradedAt;
    }
}
