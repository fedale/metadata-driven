<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Command;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SaveFormCommand
{
    /**
     * @param string $definition the YAML definition (see FormDefinitionCompiler)
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 50)]
        public string $name,
        #[Assert\NotBlank]
        #[Assert\Length(max: 50000)]
        public string $definition,
    ) {
    }
}
