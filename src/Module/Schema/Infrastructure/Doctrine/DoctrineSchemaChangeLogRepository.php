<?php

declare(strict_types=1);

namespace App\Module\Schema\Infrastructure\Doctrine;

use App\Module\Schema\Domain\Model\SchemaChangeLog;
use App\Module\Schema\Domain\Repository\SchemaChangeLogRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineSchemaChangeLogRepository implements SchemaChangeLogRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function add(SchemaChangeLog $log): void
    {
        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    public function findByObjectName(string $objectName): array
    {
        /** @var list<SchemaChangeLog> $logs */
        $logs = $this->entityManager->getRepository(SchemaChangeLog::class)
            ->findBy(['objectName' => $objectName], ['appliedAt' => 'DESC', 'id' => 'DESC']);

        return $logs;
    }
}
