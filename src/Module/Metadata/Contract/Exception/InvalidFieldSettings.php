<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Exception;

use App\Shared\Message\Message;
use App\Shared\Message\MessageList;

final class InvalidFieldSettings extends MetadataException
{
    /**
     * @param list<string|Message> $errors
     */
    public function __construct(
        public readonly string $type,
        public readonly array $errors,
    ) {
        parent::__construct(new Message('metadata.invalid_field_settings', 'Invalid settings for field type "%type%": %errors%', ['type' => $type, 'errors' => new MessageList($errors, ' ')]));
    }
}
