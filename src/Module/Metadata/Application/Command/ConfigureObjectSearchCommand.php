<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Command;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ConfigureObjectSearchCommand
{
    /**
     * @param string|null       $handler null for the default handler
     * @param list<string>|null $fields  null for the default fields (every text-like field)
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $objectName,
        #[Assert\Length(min: 1, max: 50)]
        public ?string $handler = null,
        #[Assert\All([new Assert\NotBlank()])]
        public ?array $fields = null,
    ) {
    }
}
