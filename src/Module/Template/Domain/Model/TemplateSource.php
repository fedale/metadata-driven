<?php

declare(strict_types=1);

namespace App\Module\Template\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * An index of templates this instance looks into: a company's private one, a vendor's own. The
 * template catalog, when configured, is one too, though not stored here.
 */
class TemplateSource
{
    public function __construct(
        private Uuid $id,
        /** How commands name it: "acme", "company". */
        private string $name,
        /** The address of its index.json, https. */
        private string $url,
        private \DateTimeImmutable $addedAt,
    ) {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }
}
