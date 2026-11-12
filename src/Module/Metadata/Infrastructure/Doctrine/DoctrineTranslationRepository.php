<?php

declare(strict_types=1);

namespace App\Module\Metadata\Infrastructure\Doctrine;

use App\Module\Metadata\Domain\Model\Translation;
use App\Module\Metadata\Domain\Repository\TranslationRepository;
use App\Shared\Database\TenantScope;
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
        $this->write('DELETE FROM %1$s WHERE %3$s AND %2$s = ?', [$key]);
    }

    public function removeKeysUnder(array $prefixes): void
    {
        foreach ($prefixes as $prefix) {
            // LIKE wildcards in a prefix would widen the match: escape them.
            $this->write('DELETE FROM %1$s WHERE %3$s AND (%2$s = ? OR %2$s LIKE ?)', [$prefix, addcslashes($prefix, '%_\\').'.%']);
        }
    }

    public function moveKeysUnder(array $prefixes): void
    {
        foreach ($prefixes as $from => $to) {
            // substr() counts from 1: what follows the prefix, its dot included.
            $this->write(
                'UPDATE %1$s SET %2$s = ? || substr(%2$s, ?) WHERE %3$s AND (%2$s = ? OR %2$s LIKE ?)',
                [$to, \strlen($from) + 1, $from, addcslashes($from, '%_\\').'.%'],
            );
        }
    }

    /**
     * A write to the current tenant's translations, in SQL: Doctrine's filters apply to
     * SELECTs only, so a DQL UPDATE or DELETE would reach every tenant's rows.
     *
     * @param string           $sql        %1$s the table, %2$s the key column, %3$s the tenant condition
     * @param list<string|int> $parameters
     */
    private function write(string $sql, array $parameters): void
    {
        $metadata = $this->entityManager->getClassMetadata(Translation::class);
        $table = (null === $metadata->getSchemaName() ? '' : $metadata->getSchemaName().'.').$metadata->getTableName();
        $this->entityManager->getConnection()->executeStatement(
            \sprintf($sql, $table, $metadata->getColumnName('key'), TenantScope::COLUMN.' = '.TenantScope::CURRENT),
            $parameters,
        );
    }
}
