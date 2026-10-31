<?php

declare(strict_types=1);

namespace App\Module\Metadata\Domain\Model;

use Symfony\Component\Uid\Uuid;

/**
 * The text of a translation key in one language.
 *
 * A key derived from the metadata (a field label, an option...) has its default text in the
 * metadata itself: its rows hold the other languages only. A text key ("text.sign_in", used by
 * {t:sign_in} tokens) has no metadata behind it: its row in the default language defines it.
 */
class Translation
{
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        private Uuid $id,
        private string $locale,
        private string $key,
        private string $text,
    ) {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function change(string $text): void
    {
        $this->text = $text;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
