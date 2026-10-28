<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Command;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ConfigureFieldSettingsCommand
{
    /**
     * @param array<string, mixed> $settings the settings to change; the others keep their value
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $objectName,
        #[Assert\NotBlank]
        public string $fieldName,
        public array $settings,
    ) {
    }
}
