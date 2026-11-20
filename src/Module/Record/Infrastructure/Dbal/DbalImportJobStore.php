<?php

declare(strict_types=1);

namespace App\Module\Record\Infrastructure\Dbal;

use App\Module\Record\Domain\Model\ImportJob;
use App\Module\Record\Domain\Model\ImportJobStatus;
use App\Module\Record\Domain\Repository\ImportJobRepository;
use App\Shared\Database\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

/**
 * Row-level security keeps every query in the current workspace; each also names it, for the
 * index. The workspace of a new row is the trigger's (core.set_workspace_id()).
 */
final class DbalImportJobStore implements ImportJobRepository
{
    private const string MINE = 'workspace_id = '.WorkspaceScope::CURRENT;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function add(ImportJob $job): void
    {
        $now = new \DateTimeImmutable();
        $this->connection->insert('core.record_import', [
            'id' => $job->id,
            'object' => $job->object,
            'original_name' => mb_substr($job->originalName, 0, 255),
            'separator' => $job->separator,
            'mapping' => json_encode($job->mapping, \JSON_THROW_ON_ERROR),
            'key_field' => $job->key,
            'matches' => json_encode((object) $job->matches, \JSON_THROW_ON_ERROR),
            'created_by' => mb_strtolower($job->createdBy),
            'locale' => $job->locale,
            'status' => $job->status->value,
            'total_rows' => $job->totalRows,
            'created_at' => $job->createdAt,
            'updated_at' => $now,
        ], ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
        $job->updatedAt = $now;
    }

    public function get(string $id): ?ImportJob
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM core.record_import WHERE '.self::MINE.' AND id = :id', ['id' => $id]);

        return false === $row ? null : self::job($row);
    }

    public function latest(string $createdBy, string $object, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT * FROM core.record_import WHERE %s AND created_by = :creator AND object = :object ORDER BY created_at DESC, id DESC LIMIT %d', self::MINE, max(1, $limit)),
            ['creator' => mb_strtolower($createdBy), 'object' => $object],
        );

        return array_map(self::job(...), $rows);
    }

    public function save(ImportJob $job): void
    {
        $job->updatedAt = new \DateTimeImmutable();
        $this->connection->update('core.record_import', [
            'status' => $job->status->value,
            'processed' => $job->processed,
            'created' => $job->created,
            'updated' => $job->updated,
            'reasons' => json_encode($job->reasons, \JSON_THROW_ON_ERROR),
            'reason_count' => $job->reasonCount,
            'stopped_at_line' => $job->stoppedAtLine,
            'updated_at' => $job->updatedAt,
        ], ['id' => $job->id], ['updated_at' => Types::DATETIME_IMMUTABLE]);
    }

    public function transition(ImportJob $job, ImportJobStatus $from, ImportJobStatus $to): bool
    {
        $moved = 1 === (int) $this->connection->executeStatement(
            'UPDATE core.record_import SET status = :to, stop_requested = false, updated_at = :now WHERE '.self::MINE.' AND id = :id AND status = :from',
            ['to' => $to->value, 'from' => $from->value, 'id' => $job->id, 'now' => new \DateTimeImmutable()],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
        if ($moved) {
            $job->status = $to;
            $job->stopRequested = false;
        }

        return $moved;
    }

    public function requestStop(ImportJob $job): void
    {
        $this->connection->executeStatement('UPDATE core.record_import SET stop_requested = true WHERE '.self::MINE.' AND id = :id', ['id' => $job->id]);
        $job->stopRequested = true;
    }

    public function stopRequested(string $id): bool
    {
        return true === $this->connection->fetchOne('SELECT stop_requested FROM core.record_import WHERE '.self::MINE.' AND id = :id', ['id' => $id]);
    }

    public function delete(ImportJob $job): void
    {
        $this->connection->delete('core.record_import', ['id' => $job->id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function job(array $row): ImportJob
    {
        $json = static function (mixed $value): array {
            $decoded = \is_string($value) ? json_decode($value, true, 512, \JSON_THROW_ON_ERROR) : [];

            return \is_array($decoded) ? $decoded : [];
        };
        $mapping = [];
        foreach ($json($row['mapping']) as $header => $field) {
            $mapping[(string) $header] = \is_string($field) ? $field : null;
        }
        $matches = [];
        foreach ($json($row['matches']) as $field => $target) {
            if (\is_string($target)) {
                $matches[(string) $field] = $target;
            }
        }

        return new ImportJob(
            self::text($row['id']),
            self::text($row['object']),
            self::text($row['original_name']),
            self::text($row['separator']),
            $mapping,
            \is_string($row['key_field']) ? $row['key_field'] : null,
            $matches,
            self::text($row['created_by']),
            self::text($row['locale']),
            self::int($row['total_rows']),
            new \DateTimeImmutable(self::text($row['created_at'])),
            ImportJobStatus::from(self::text($row['status'])),
            true === $row['stop_requested'],
            self::int($row['processed']),
            self::int($row['created']),
            self::int($row['updated']),
            array_values(array_filter($json($row['reasons']), is_string(...))),
            self::int($row['reason_count']),
            null === $row['stopped_at_line'] ? null : self::int($row['stopped_at_line']),
            new \DateTimeImmutable(self::text($row['updated_at'])),
        );
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) || (\is_string($value) && is_numeric($value)) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException(\sprintf('A text was expected from core.record_import, not a %s.', get_debug_type($value)));
    }
}
