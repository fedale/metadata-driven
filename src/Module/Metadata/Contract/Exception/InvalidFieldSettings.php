<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Exception;

final class InvalidFieldSettings extends MetadataException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public readonly string $type,
        public readonly array $errors,
    ) {
        parent::__construct(\sprintf('Invalid settings for field type "%s": %s', $type, implode(' ', $errors)));
    }
}
