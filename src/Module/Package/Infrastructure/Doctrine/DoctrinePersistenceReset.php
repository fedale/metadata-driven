<?php

declare(strict_types=1);

namespace App\Module\Package\Infrastructure\Doctrine;

use App\Module\Package\Domain\Repository\PersistenceReset;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Clears the entity manager. Every managed entity is detached, not only those of the
 * metadata: a caller holding one (a web request's user) must not flush it afterwards.
 */
final class DoctrinePersistenceReset implements PersistenceReset
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function reset(): void
    {
        $this->entityManager->clear();
    }
}
