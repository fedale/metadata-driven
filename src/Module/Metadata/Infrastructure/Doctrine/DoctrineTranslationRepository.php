<?php

declare(strict_types=1);

namespace App\Module\Metadata\Infrastructure\Doctrine;

use App\Module\Metadata\Domain\Model\Translation;
use App\Module\Metadata\Domain\Repository\TranslationRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineTranslationRepository implements TranslationRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Translation $translation): void
    {
        $this->entityManager->persist($translation);
        $this->entityManager->flush();
    }

    public function remove(Translation $translation): void
    {
        $this->entityManager->remove($translation);
        $this->entityManager->flush();
    }

    public function find(string $locale, string $key): ?Translation
    {
        return $this->entityManager->getRepository(Translation::class)->findOneBy(['locale' => $locale, 'key' => $key]);
    }

    public function textsOf(string $locale): array
    {
        /** @var list<array{key: string, text: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('t.key', 't.text')
            ->from(Translation::class, 't')
            ->where('t.locale = :locale')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'text', 'key');
    }

    public function removeKey(string $key): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(Translation::class, 't')
            ->where('t.key = :key')
            ->setParameter('key', $key)
            ->getQuery()
            ->execute();
    }

    public function removeKeysUnder(array $prefixes): void
    {
        foreach ($prefixes as $prefix) {
            // LIKE wildcards in a prefix would widen the match: escape them.
            $like = addcslashes($prefix, '%_\\').'.%';
            $this->entityManager->createQueryBuilder()
                ->delete(Translation::class, 't')
                ->where('t.key = :key OR t.key LIKE :under')
                ->setParameter('key', $prefix)
                ->setParameter('under', $like)
                ->getQuery()
                ->execute();
        }
    }
}
