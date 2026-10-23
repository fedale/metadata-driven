<?php

declare(strict_types=1);

namespace App\Module\Metadata\Infrastructure\Doctrine;

use App\Module\Metadata\Domain\Model\FormDefinition;
use App\Module\Metadata\Domain\Repository\FormDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineFormDefinitionRepository implements FormDefinitionRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(FormDefinition $form): void
    {
        $this->entityManager->persist($form);
        $this->entityManager->flush();
    }

    public function remove(FormDefinition $form): void
    {
        $this->entityManager->remove($form);
        $this->entityManager->flush();
    }

    public function findByName(string $name): ?FormDefinition
    {
        return $this->entityManager->getRepository(FormDefinition::class)->findOneBy(['name' => $name]);
    }

    public function findAll(): array
    {
        /** @var list<FormDefinition> $forms */
        $forms = $this->entityManager->getRepository(FormDefinition::class)->findBy([], ['name' => 'ASC']);

        return $forms;
    }
}
