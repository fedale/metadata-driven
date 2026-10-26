<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Command;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ConfigureFieldConditionCommand
{
    /**
     * @param string|null  $when   the deciding field; null removes the condition (the field always applies)
     * @param list<string> $values the values of the deciding field for which the field applies
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $objectName,
        #[Assert\NotBlank]
        public string $fieldName,
        #[Assert\Length(min: 1, max: 50)]
        public ?string $when = null,
        #[Assert\All([new Assert\NotBlank(), new Assert\Length(max: 100)])]
        public array $values = [],
    ) {
    }
}
