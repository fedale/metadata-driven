<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Command;

use App\Module\Metadata\Contract\Dto\ViewKind;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SaveViewCommand
{
    /**
     * @param string $template  HTML with {field} tokens; sanitized before it is stored
     * @param bool   $isDefault make it the default view of its kind (the first view of a kind always is)
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $objectName,
        #[Assert\NotBlank]
        #[Assert\Length(max: 50)]
        public string $name,
        #[Assert\NotBlank]
        #[Assert\Length(max: 20000)]
        public string $template,
        public ViewKind $kind = ViewKind::Form,
        public bool $isDefault = false,
    ) {
    }
}
