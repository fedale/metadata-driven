<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Command;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class DeleteFormCommand
{
    public function __construct(
        #[Assert\NotBlank]
        public string $name,
    ) {
    }
}
