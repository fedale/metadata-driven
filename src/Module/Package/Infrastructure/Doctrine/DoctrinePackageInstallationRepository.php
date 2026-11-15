<?php

declare(strict_types=1);

namespace App\Module\Package\Infrastructure\Doctrine;

use App\Module\Package\Domain\Model\PackageInstallation;
use App\Module\Package\Domain\Repository\PackageInstallationRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrinePackageInstallationRepository implements PackageInstallationRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findByName(string $name): ?PackageInstallation
    {
        return $this->entityManager->getRepository(PackageInstallation::class)->findOneBy(['name' => $name]);
    }

    public function all(): array
    {
        return $this->entityManager->getRepository(PackageInstallation::class)->findBy([], ['name' => 'ASC']);
    }

    public function save(PackageInstallation $installation): void
    {
        $this->entityManager->persist($installation);
        $this->entityManager->flush();
    }
}
