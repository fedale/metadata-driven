<?php

declare(strict_types=1);

namespace App\Module\File\Domain\Repository;

use App\Module\File\Domain\Model\File;
use Symfony\Component\Uid\Uuid;

interface FileRepository
{
    public function save(File $file): void;

    public function find(Uuid $id): ?File;
}
