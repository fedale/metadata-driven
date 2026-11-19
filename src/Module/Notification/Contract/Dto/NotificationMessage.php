<?php

declare(strict_types=1);

namespace App\Module\Notification\Contract\Dto;

/**
 * What a notification says: a title, a text, and the page it leads to.
 *
 * The link is a path of the workspace ("/o/invoice/…"), never an address of its own: following a
 * notification never leaves the application.
 */
final readonly class NotificationMessage
{
    public const int MAX_TITLE = 200;

    public const int MAX_TEXT = 2000;

    public const int MAX_LINK = 500;

    public string $title;

    public ?string $text;

    /**
     * @param string|null $link   a path of the workspace, starting with one slash
     * @param string|null $origin where it comes from, shown with it: "Workflow Reminders"
     *
     * @throws \InvalidArgumentException no title, or a link that is no path of the workspace
     */
    public function __construct(
        string $title,
        ?string $text = null,
        public ?string $link = null,
        public ?string $origin = null,
    ) {
        $title = trim($title);
        if ('' === $title) {
            throw new \InvalidArgumentException('A notification has a title.');
        }
        if (null !== $link && (1 !== preg_match('~^/(?![/\\\\])~', $link) || \strlen($link) > self::MAX_LINK || 1 === preg_match('~[\s\\\\]~', $link))) {
            throw new \InvalidArgumentException(\sprintf('A notification leads to a path of the workspace, not to "%s".', $link));
        }
        // Cut rather than refused: the texts come from a workflow's data, whatever their length.
        $this->title = mb_substr($title, 0, self::MAX_TITLE);
        $text = null === $text ? null : trim($text);
        $this->text = null === $text || '' === $text ? null : mb_substr($text, 0, self::MAX_TEXT);
    }
}
