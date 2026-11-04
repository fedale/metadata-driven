<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Command;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ConfigureUniqueTogetherCommand
{
    /**
     * @param list<string> $fields the fields of the set, in the order of its index
     * @param bool         $remove true to remove the set (named in any order) instead of adding it
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $objectName,
        #[Assert\Count(min: 2, minMessage: 'Name at least two fields.')]
        #[Assert\All([new Assert\NotBlank()])]
        public array $fields,
        public bool $remove = false,
    ) {
    }
}
