<?php

declare(strict_types=1);

namespace App\Module\Package\Domain\Exception;

use App\Module\Package\Contract\Exception\PackageException;
use App\Shared\Message\Message;
use App\Shared\Message\MessageList;

/**
 * An upgrade that changes nothing: every reason found, each naming the document and the item
 * it comes from.
 *
 * A reason is a Message, to be read in the user's language, or a plain string: a problem the
 * package format's checks found, which is English only.
 */
final class UpgradeRefused extends PackageException
{
    /**
     * @param list<string|Message> $reasons
     */
    public function __construct(
        public readonly array $reasons,
        public readonly bool $conflicts = false,
    ) {
        parent::__construct(1 === \count($reasons)
            ? new Message('package.upgrade.refused', 'The package cannot be upgraded: %reason%', ['reason' => $reasons[0]])
            : new Message('package.upgrade.refused_many', 'The package cannot be upgraded (%count% reasons): %reasons%', ['count' => \count($reasons), 'reasons' => new MessageList($reasons)]));
    }

    public static function notInstalled(string $name): self
    {
        return new self([new Message('package.upgrade.not_installed', '%name% is not installed: install it first.', ['name' => $name])]);
    }

    public static function notNewer(string $name, string $installed, string $version): self
    {
        return new self([new Message('package.upgrade.not_newer', '%name% %installed% is installed: %version% is not newer.', ['name' => $name, 'installed' => $installed, 'version' => $version])]);
    }

    /**
     * @param list<string|Message> $conflicts
     */
    public static function conflicts(array $conflicts): self
    {
        return new self($conflicts, conflicts: true);
    }

    /**
     * @param string|Message             $item    the item, or the place in the document
     * @param string|Message|MessageList $message why, as the app gave it
     */
    public static function at(string $path, string|Message $item, string|Message|MessageList $message): self
    {
        return new self([InstallRefused::located($path, $item, $message)]);
    }
}
