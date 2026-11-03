<?php

declare(strict_types=1);

namespace App\Module\File\Infrastructure\Doctrine;

use App\Module\File\Domain\Model\File;
use App\Module\File\Domain\Repository\FileRepository;
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

    public function find(Uuid $id): ?File
    {
        return $this->entityManager->find(File::class, $id);
    }
}
