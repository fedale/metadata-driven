<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Command;

use App\Module\Metadata\Contract\Dto\ViewKind;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class DeleteViewCommand
{
    public function __construct(
        #[Assert\NotBlank]
        public string $objectName,
        #[Assert\NotBlank]
        public string $name,
        public ViewKind $kind = ViewKind::Form,
    ) {
    }
}
