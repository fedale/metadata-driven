<?php

declare(strict_types=1);

namespace App\Module\Template\Application;

use App\Module\Template\Domain\Model\ItemKind;
use App\Module\Template\Domain\Model\TemplateItem;
use Symfony\Component\Uid\Uuid;

/**
 * The ids of a template's items during one export: the ones recorded before are kept, renamed
 * item or not; a new item gets one made of its names, made unique if an item of the same kind
 * already holds it (an object renamed, and a new one created under its old name).
 */
final class ItemIds
{
    /** @var array<string, string> "kind localId" => item id */
    private array $known = [];

    /** @var array<string, true> "kind itemId" */
    private array $taken = [];

    /** @var list<TemplateItem> */
    private array $new = [];

    /**
     * @param list<TemplateItem> $recorded
     */
    public function __construct(
        private readonly string $template,
        array $recorded,
    ) {
        foreach ($recorded as $item) {
            $this->known[$item->getKind()->value.' '.$item->getLocalId()] = $item->getItemId();
            $this->taken[$item->getKind()->value.' '.$item->getItemId()] = true;
        }
    }

    public function of(ItemKind $kind, string $localId, string $wanted): string
    {
        $known = $this->known[$kind->value.' '.$localId] ?? null;
        if (null !== $known) {
            return $known;
        }

        $id = $wanted;
        for ($n = 2; isset($this->taken[$kind->value.' '.$id]); ++$n) {
            $id = $wanted.'_'.$n;
        }
        $this->known[$kind->value.' '.$localId] = $id;
        $this->taken[$kind->value.' '.$id] = true;
        $this->new[] = new TemplateItem(Uuid::v7(), $this->template, $kind, $id, $localId);

        return $id;
    }

    /**
     * The ids made during this export, to record once it succeeds.
     *
     * @return list<TemplateItem>
     */
    public function newItems(): array
    {
        return $this->new;
    }
}
