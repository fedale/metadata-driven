<?php

declare(strict_types=1);

namespace App\Module\Template\Infrastructure\Doctrine;

use App\Module\Template\Domain\Model\TemplateSource;
use App\Module\Template\Domain\Repository\TemplateSourceRepository;
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
