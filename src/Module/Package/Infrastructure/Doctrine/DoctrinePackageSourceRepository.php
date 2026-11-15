<?php

declare(strict_types=1);

namespace App\Module\Package\Infrastructure\Doctrine;

use App\Module\Package\Domain\Model\PackageSource;
use App\Module\Package\Domain\Repository\PackageSourceRepository;
use App\Shared\Database\WorkspaceScope;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrinePackageSourceRepository implements PackageSourceRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function all(): array
    {
        return $this->entityManager->getRepository(PackageSource::class)->findBy([], ['name' => 'ASC']);
    }

    public function isShared(PackageSource $source): bool
    {
        return true === $this->entityManager->getConnection()->fetchOne(
            'SELECT workspace_id IS NULL FROM core.package_source WHERE id = ?',
            [$source->getId()->toRfc4122()],
        );
    }

    public function isNameTaken(string $name): bool
    {
        // As the system (no workspace), any workspace's: a shared index must not shadow one of them.
        return false !== $this->entityManager->getConnection()->fetchOne(
            \sprintf('SELECT 1 FROM core.package_source WHERE name = ? AND (workspace_id IS NULL OR workspace_id = %1$s OR %1$s IS NULL)', WorkspaceScope::CURRENT),
            [$name],
        );
    }

    public function findByName(string $name): ?PackageSource
    {
        return $this->entityManager->getRepository(PackageSource::class)->findOneBy(['name' => $name]);
    }

    public function save(PackageSource $source): void
    {
        $this->entityManager->persist($source);
        $this->entityManager->flush();
    }

    public function remove(PackageSource $source): void
    {
        $this->entityManager->remove($source);
        $this->entityManager->flush();
    }
}
