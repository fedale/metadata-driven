<?php

declare(strict_types=1);

namespace App\Module\Metadata\Infrastructure\Doctrine;

use App\Module\Metadata\Domain\Model\ObjectDefinition;
use App\Module\Metadata\Domain\Repository\ObjectDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineObjectDefinitionRepository implements ObjectDefinitionRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ObjectDefinition $object): void
    {
        $this->entityManager->persist($object);
        $this->entityManager->flush();
    }

    public function remove(ObjectDefinition $object): void
    {
        $this->entityManager->remove($object);
        $this->entityManager->flush();
    }

    public function findByName(string $name): ?ObjectDefinition
    {
        return $this->entityManager->getRepository(ObjectDefinition::class)->findOneBy(['name' => $name]);
    }

    public function findAll(): array
    {
        /** @var list<ObjectDefinition> $objects */
        $objects = $this->entityManager->createQueryBuilder()
            ->select('o', 'f')
            ->from(ObjectDefinition::class, 'o')
            ->leftJoin('o.fields', 'f')
            ->orderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $objects;
    }
}
