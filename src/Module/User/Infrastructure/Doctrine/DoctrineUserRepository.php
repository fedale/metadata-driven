<?php

declare(strict_types=1);

namespace App\Module\User\Infrastructure\Doctrine;

use App\Module\User\Domain\Model\User;
use App\Module\User\Domain\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineUserRepository implements UserRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(User $user): void
    {
        $this->entityManager->persist($user);
        if (null !== $user->getProfile()) {
            $this->entityManager->persist($user->getProfile());
        }
        $this->entityManager->flush();
    }

    public function operators(): array
    {
        return $this->entityManager->getRepository(User::class)->findBy(['isOperator' => true], ['email' => 'ASC']);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim($email))]);
    }
}
