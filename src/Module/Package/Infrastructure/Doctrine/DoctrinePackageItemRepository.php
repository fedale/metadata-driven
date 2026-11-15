<?php

declare(strict_types=1);

namespace App\Module\Package\Infrastructure\Doctrine;

use App\Module\Package\Domain\Model\PackageItem;
use App\Module\Package\Domain\Repository\PackageItemRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrinePackageItemRepository implements PackageItemRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function itemsOf(string $package): array
    {
        return $this->entityManager->getRepository(PackageItem::class)->findBy(['package' => $package]);
    }

    public function add(array $items): void
    {
        foreach ($items as $item) {
            $this->entityManager->persist($item);
        }
        // One flush: Doctrine writes them in one transaction.
        $this->entityManager->flush();
    }

    public function remove(string $package, array $localIds): void
    {
        foreach ($this->entityManager->getRepository(PackageItem::class)->findBy(['package' => $package, 'localId' => $localIds]) as $item) {
            $this->entityManager->remove($item);
        }
        $this->entityManager->flush();
    }
}
