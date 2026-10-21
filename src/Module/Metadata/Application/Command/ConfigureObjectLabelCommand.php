<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Command;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ConfigureObjectLabelCommand
{
    /**
     * @param string|null $field null for the default (the first text-like field)
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $objectName,
        #[Assert\Length(min: 1, max: 50)]
        public ?string $field = null,
    ) {
    }
}
