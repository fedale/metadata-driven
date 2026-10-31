<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Command;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TranslateCommand
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Locale]
        public string $locale,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $key,
        #[Assert\Length(max: 2000)]
        public ?string $text,
    ) {
    }
}
