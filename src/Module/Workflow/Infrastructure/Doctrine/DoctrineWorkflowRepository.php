<?php

declare(strict_types=1);

namespace App\Module\Workflow\Infrastructure\Doctrine;

use App\Module\Workflow\Domain\Model\Workflow;
use App\Module\Workflow\Domain\Model\WorkflowVersion;
use App\Module\Workflow\Domain\Repository\WorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineWorkflowRepository implements WorkflowRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function all(): array
    {
        return $this->entityManager->getRepository(Workflow::class)->findBy([], ['position' => 'ASC', 'name' => 'ASC']);
    }

    public function find(Uuid $id): ?Workflow
    {
        return $this->entityManager->find(Workflow::class, $id);
    }

    public function findByName(string $name): ?Workflow
    {
        return $this->entityManager->getRepository(Workflow::class)->findOneBy(['name' => $name]);
    }

    public function nextPosition(): int
    {
        $max = $this->entityManager->createQuery('SELECT MAX(w.position) FROM '.Workflow::class.' w')->getSingleScalarResult();

        return is_numeric($max) ? (int) $max + 1 : 0;
    }

    public function versionsOf(Workflow $workflow): array
    {
        return $this->entityManager->getRepository(WorkflowVersion::class)->findBy(['workflow' => $workflow], ['number' => 'DESC']);
    }

    public function findVersion(Uuid $id): ?WorkflowVersion
    {
        return $this->entityManager->find(WorkflowVersion::class, $id);
    }

    public function save(Workflow|WorkflowVersion ...$items): void
    {
        foreach ($items as $item) {
            $this->entityManager->persist($item);
        }
        $this->entityManager->flush();
    }

    public function remove(Workflow $workflow): void
    {
        $this->entityManager->remove($workflow);
        $this->entityManager->flush();
    }
}
