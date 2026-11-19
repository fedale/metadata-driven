<?php

declare(strict_types=1);

namespace App\Module\User\Infrastructure\Dbal;

use App\Module\User\Domain\Repository\PasswordResetRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

final class DbalPasswordResetRepository implements PasswordResetRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function replace(Uuid $userId, string $tokenHash, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt): void
    {
        $this->connection->transactional(function (Connection $connection) use ($userId, $tokenHash, $createdAt, $expiresAt): void {
            $connection->executeStatement('DELETE FROM core.password_reset WHERE user_id = ? AND used_at IS NULL', [$userId->toRfc4122()]);
            $connection->insert('core.password_reset', [
                'id' => Uuid::v7()->toRfc4122(),
                'user_id' => $userId->toRfc4122(),
                'token_hash' => $tokenHash,
                'created_at' => $createdAt,
                'expires_at' => $expiresAt,
            ], [
                'created_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        });
    }

    public function pendingEmail(string $tokenHash, \DateTimeImmutable $now): ?string
    {
        $email = $this->connection->fetchOne(
            'SELECT u.email FROM core.password_reset r JOIN core.app_user u ON u.id = r.user_id WHERE r.token_hash = :hash AND r.used_at IS NULL AND r.expires_at > :now',
            ['hash' => $tokenHash, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        return \is_string($email) ? $email : null;
    }

    public function use(string $tokenHash, \DateTimeImmutable $now): bool
    {
        // One statement: of two requests with the same link, one only finds it unused.
        return 1 === $this->connection->executeStatement(
            'UPDATE core.password_reset SET used_at = :now WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now',
            ['hash' => $tokenHash, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }
}
