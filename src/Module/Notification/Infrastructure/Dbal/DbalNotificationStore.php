<?php

declare(strict_types=1);

namespace App\Module\Notification\Infrastructure\Dbal;

use App\Module\Notification\Application\NotificationStore;
use App\Module\Notification\Application\NotificationView;
use App\Module\Notification\Contract\Dto\NotificationMessage;
use App\Shared\Database\WorkspaceScope;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

/**
 * Row-level security keeps every query in the current workspace; each also names it, for the
 * indexes, and names its recipient, for the user's own only. The workspace of a new row is the
 * trigger's (core.set_workspace_id()).
 */
final class DbalNotificationStore implements NotificationStore
{
    private const string MINE = 'workspace_id = '.WorkspaceScope::CURRENT.' AND recipient = :recipient';

    private const string COLUMNS = 'id, title, body, link, origin, created_at, read_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function add(string $recipient, NotificationMessage $message): void
    {
        $this->connection->insert('core.notification', [
            'id' => Uuid::v7()->toRfc4122(),
            'recipient' => mb_strtolower($recipient),
            'title' => $message->title,
            'body' => $message->text,
            'link' => $message->link,
            'origin' => null === $message->origin ? null : mb_substr($message->origin, 0, 200),
            'created_at' => new \DateTimeImmutable(),
        ], ['created_at' => Types::DATETIME_IMMUTABLE]);
    }

    public function unreadCount(string $recipient): int
    {
        return self::int($this->connection->fetchOne('SELECT count(*) FROM core.notification WHERE '.self::MINE.' AND read_at IS NULL', ['recipient' => mb_strtolower($recipient)]));
    }

    public function count(string $recipient): int
    {
        return self::int($this->connection->fetchOne('SELECT count(*) FROM core.notification WHERE '.self::MINE, ['recipient' => mb_strtolower($recipient)]));
    }

    public function latest(string $recipient, int $limit, int $offset = 0): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT %s FROM core.notification WHERE %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', self::COLUMNS, self::MINE, max(1, $limit), max(0, $offset)),
            ['recipient' => mb_strtolower($recipient)],
        );

        return array_map(self::view(...), $rows);
    }

    public function find(string $recipient, string $id): ?NotificationView
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT %s FROM core.notification WHERE %s AND id = :id', self::COLUMNS, self::MINE),
            ['recipient' => mb_strtolower($recipient), 'id' => $id],
        );

        return false === $row ? null : self::view($row);
    }

    public function markRead(string $recipient, string $id): void
    {
        if (!Uuid::isValid($id)) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE core.notification SET read_at = :now WHERE '.self::MINE.' AND id = :id AND read_at IS NULL',
            ['recipient' => mb_strtolower($recipient), 'id' => $id, 'now' => new \DateTimeImmutable()],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function markAllRead(string $recipient): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE core.notification SET read_at = :now WHERE '.self::MINE.' AND read_at IS NULL',
            ['recipient' => mb_strtolower($recipient), 'now' => new \DateTimeImmutable()],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM core.notification WHERE workspace_id = '.WorkspaceScope::CURRENT.' AND created_at < :before',
            ['before' => $before],
            ['before' => Types::DATETIME_IMMUTABLE],
        );
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) || (\is_string($value) && is_numeric($value)) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException(\sprintf('A text was expected from core.notification, not a %s.', get_debug_type($value)));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function view(array $row): NotificationView
    {
        return new NotificationView(
            self::text($row['id']),
            self::text($row['title']),
            \is_string($row['body']) ? $row['body'] : null,
            \is_string($row['link']) ? $row['link'] : null,
            \is_string($row['origin']) ? $row['origin'] : null,
            new \DateTimeImmutable(self::text($row['created_at'])),
            \is_string($row['read_at']) ? new \DateTimeImmutable($row['read_at']) : null,
        );
    }
}
