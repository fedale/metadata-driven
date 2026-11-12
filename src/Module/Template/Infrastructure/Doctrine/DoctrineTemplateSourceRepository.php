<?php

declare(strict_types=1);

namespace App\Module\Template\Infrastructure\Doctrine;

use App\Module\Template\Domain\Model\TemplateSource;
use App\Module\Template\Domain\Repository\TemplateSourceRepository;
use App\Shared\Database\TenantScope;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineTemplateSourceRepository implements TemplateSourceRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function all(): array
    {
        return $this->entityManager->getRepository(TemplateSource::class)->findBy([], ['name' => 'ASC']);
    }

    public function isShared(TemplateSource $source): bool
    {
        return true === $this->entityManager->getConnection()->fetchOne(
            'SELECT tenant_id IS NULL FROM core.template_source WHERE id = ?',
            [$source->getId()->toRfc4122()],
        );
    }

    public function isNameTaken(string $name): bool
    {
        // As the landlord (no tenant), any tenant's: a shared index must not shadow one of them.
        return false !== $this->entityManager->getConnection()->fetchOne(
            \sprintf('SELECT 1 FROM core.template_source WHERE name = ? AND (tenant_id IS NULL OR tenant_id = %1$s OR %1$s IS NULL)', TenantScope::CURRENT),
            [$name],
        );
    }

    public function findByName(string $name): ?TemplateSource
    {
        return $this->entityManager->getRepository(TemplateSource::class)->findOneBy(['name' => $name]);
    }

    public function save(TemplateSource $source): void
    {
        $this->entityManager->persist($source);
        $this->entityManager->flush();
    }

    public function remove(TemplateSource $source): void
    {
        $this->entityManager->remove($source);
        $this->entityManager->flush();
    }
}
