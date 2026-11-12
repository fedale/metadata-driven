<?php

declare(strict_types=1);

namespace App\Module\File\Infrastructure\Doctrine;

use App\Module\File\Domain\Model\File;
use App\Module\File\Domain\Repository\FileRepository;
use App\Module\File\Infrastructure\Dbal\ContentLock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineFileRepository implements FileRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(File $file): void
    {
        $this->entityManager->persist($file);
        $this->entityManager->flush();
    }

    public function underContentLock(string $storageKey, callable $write): mixed
    {
        $connection = $this->entityManager->getConnection();

        return $connection->transactional(static function () use ($connection, $storageKey, $write): mixed {
            ContentLock::acquire($connection, $storageKey);

            return $write();
        });
    }

    public function find(Uuid $id): ?File
    {
        return $this->entityManager->find(File::class, $id);
    }

    public function findMany(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->entityManager->getRepository(File::class)->findBy(['id' => $ids]);
    }
}
