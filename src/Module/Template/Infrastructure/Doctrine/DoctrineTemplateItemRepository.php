<?php

declare(strict_types=1);

namespace App\Module\Template\Infrastructure\Doctrine;

use App\Module\Template\Domain\Model\TemplateItem;
use App\Module\Template\Domain\Repository\TemplateItemRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineTemplateItemRepository implements TemplateItemRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function itemsOf(string $template): array
    {
        return $this->entityManager->getRepository(TemplateItem::class)->findBy(['template' => $template]);
    }

    public function add(array $items): void
    {
        foreach ($items as $item) {
            $this->entityManager->persist($item);
        }
        // One flush: Doctrine writes them in one transaction.
        $this->entityManager->flush();
    }
}
