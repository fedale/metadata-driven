<?php

declare(strict_types=1);

namespace App\Module\Template\Domain\Repository;

use App\Module\Template\Domain\Model\TemplateSource;

interface TemplateSourceRepository
{
    /**
     * @return list<TemplateSource> by name
     */
    public function all(): array;

    public function findByName(string $name): ?TemplateSource;

    public function save(TemplateSource $source): void;

    public function remove(TemplateSource $source): void;
}
