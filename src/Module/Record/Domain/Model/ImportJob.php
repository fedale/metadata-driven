<?php

declare(strict_types=1);

namespace App\Module\Record\Domain\Model;

/**
 * A CSV import checked and run by the worker, batch by batch: its file (a file of its own, named
 * after its id), the columns, key and matches its creator chose in the wizard, and how far it went.
 *
 * Only a sample of the reasons is kept (MAX_REASONS), and how many there were: a large file may
 * refuse every one of its rows.
 */
final class ImportJob
{
    public const int MAX_REASONS = 200;

    /**
     * @param array<string, string|null> $mapping column of the file => the field it fills; null: ignored
     * @param array<string, string>      $matches relation field => the unique field of its target its cells hold
     * @param list<string>               $reasons
     */
    public function __construct(
        public readonly string $id,
        public readonly string $object,
        public readonly string $originalName,
        public readonly string $separator,
        public readonly array $mapping,
        public readonly ?string $key,
        public readonly array $matches,
        public readonly string $createdBy,
        public readonly string $locale,
        public readonly int $totalRows,
        public readonly \DateTimeImmutable $createdAt,
        public ImportJobStatus $status = ImportJobStatus::Checking,
        public bool $stopRequested = false,
        public int $processed = 0,
        public int $created = 0,
        public int $updated = 0,
        public array $reasons = [],
        public int $reasonCount = 0,
        public ?int $stoppedAtLine = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * Starts again from the first row: a check run again by the worker after a crash.
     */
    public function restart(): void
    {
        $this->processed = 0;
        $this->created = 0;
        $this->updated = 0;
        $this->reasons = [];
        $this->reasonCount = 0;
        $this->stoppedAtLine = null;
    }

    /**
     * @param list<string> $reasons
     */
    public function addReasons(array $reasons): void
    {
        $this->reasons = [...$this->reasons, ...\array_slice($reasons, 0, max(0, self::MAX_REASONS - \count($this->reasons)))];
        $this->reasonCount += \count($reasons);
    }

    /**
     * The fields the file fills, in its order.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return array_values(array_filter($this->mapping, is_string(...)));
    }

    /**
     * The matches of the relations the file fills: a match on a field left out means nothing.
     *
     * @return array<string, string>
     */
    public function usedMatches(): array
    {
        return array_intersect_key($this->matches, array_flip($this->fields()));
    }

    /**
     * How far it went, 0 to 100.
     */
    public function percent(): int
    {
        return 0 === $this->totalRows ? 100 : min(100, intdiv($this->processed * 100, $this->totalRows));
    }
}
