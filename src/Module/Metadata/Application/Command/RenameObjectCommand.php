<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Command;

use App\Module\Metadata\Domain\Model\MachineName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RenameObjectCommand
{
    public function __construct(
        public string $objectName,
        #[Assert\NotBlank]
        #[Assert\Length(min: MachineName::MIN_LENGTH, max: MachineName::MAX_LENGTH)]
        #[Assert\Regex(pattern: MachineName::PATTERN, message: 'Use lowercase letters, digits and single underscores, starting with a letter.')]
        public string $newName,
    ) {
    }
}
