<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Command;

use App\Module\Metadata\Domain\Model\MachineName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateObjectCommand
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: MachineName::MIN_LENGTH, max: MachineName::MAX_LENGTH)]
        #[Assert\Regex(pattern: MachineName::PATTERN, message: 'Use lowercase letters, digits and single underscores, starting with a letter.')]
        public string $name,
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $labelSingular,
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $labelPlural,
        #[Assert\Length(max: 50)]
        public ?string $icon = null,
    ) {
    }
}
