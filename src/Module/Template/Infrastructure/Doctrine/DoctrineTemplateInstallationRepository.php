<?php

declare(strict_types=1);

namespace App\Module\Template\Infrastructure\Doctrine;

use App\Module\Template\Domain\Model\TemplateInstallation;
use App\Module\Template\Domain\Repository\TemplateInstallationRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineTemplateInstallationRepository implements TemplateInstallationRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findByName(string $name): ?TemplateInstallation
    {
        return $this->entityManager->getRepository(TemplateInstallation::class)->findOneBy(['name' => $name]);
    }

    public function all(): array
    {
        return $this->entityManager->getRepository(TemplateInstallation::class)->findBy([], ['name' => 'ASC']);
    }

    public function save(TemplateInstallation $installation): void
    {
        $this->entityManager->persist($installation);
        $this->entityManager->flush();
    }
}
