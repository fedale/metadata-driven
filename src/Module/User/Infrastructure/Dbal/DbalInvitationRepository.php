<?php

declare(strict_types=1);

namespace App\Module\User\Infrastructure\Dbal;

use App\Module\User\Contract\Dto\Invitation;
use App\Module\User\Domain\Repository\InvitationRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final class DbalInvitationRepository implements InvitationRepository
{
    private const string COLUMNS = 'id, email, invited_by, created_at, expires_at, keep_assignments';
    private const string PENDING = 'accepted_at IS NULL AND expires_at > :now AND failed_attempts < :max';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function add(Invitation $invitation, string $tenantId, string $tokenHash): void
    {
        $this->connection->insert('core.tenant_invitation', [
            'id' => $invitation->id,
            'tenant_id' => $tenantId,
            'email' => $invitation->email,
            'token_hash' => $tokenHash,
            'invited_by' => $invitation->invitedBy,
            'keep_assignments' => $invitation->keepAssignments,
            'created_at' => $invitation->createdAt,
            'expires_at' => $invitation->expiresAt,
        ], [
            'keep_assignments' => Types::BOOLEAN,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    public function pending(string $tenantId, \DateTimeImmutable $now, int $maxFailures): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT %s FROM core.tenant_invitation WHERE tenant_id = :tenant AND %s ORDER BY created_at DESC, id', self::COLUMNS, self::PENDING),
            ['tenant' => $tenantId, 'now' => $now, 'max' => $maxFailures],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        return array_map(self::invitation(...), $rows);
    }

    public function findPending(string $tenantId, string $tokenHash, \DateTimeImmutable $now, int $maxFailures): ?Invitation
    {
        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT %s FROM core.tenant_invitation WHERE tenant_id = :tenant AND token_hash = :hash AND %s', self::COLUMNS, self::PENDING),
            ['tenant' => $tenantId, 'hash' => $tokenHash, 'now' => $now, 'max' => $maxFailures],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        return false === $row ? null : self::invitation($row);
    }

    public function markAccepted(string $id, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement('UPDATE core.tenant_invitation SET accepted_at = ? WHERE id = ?', [$at, $id], [Types::DATETIME_IMMUTABLE]);
    }

    public function recordFailure(string $id): void
    {
        $this->connection->executeStatement('UPDATE core.tenant_invitation SET failed_attempts = failed_attempts + 1 WHERE id = ?', [$id]);
    }

    public function remove(string $tenantId, string $id): bool
    {
        return 1 === $this->connection->executeStatement('DELETE FROM core.tenant_invitation WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function invitation(array $row): Invitation
    {
        $text = static fn (string $column): string => \is_string($row[$column] ?? null) ? $row[$column] : throw new \UnexpectedValueException(\sprintf('core.tenant_invitation.%s is not a string.', $column));

        return new Invitation(
            $text('id'),
            $text('email'),
            $text('invited_by'),
            new \DateTimeImmutable($text('created_at')),
            new \DateTimeImmutable($text('expires_at')),
            true === $row['keep_assignments'],
        );
    }
}
