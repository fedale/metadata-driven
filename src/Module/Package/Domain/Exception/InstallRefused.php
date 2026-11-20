<?php

declare(strict_types=1);

namespace App\Module\Package\Domain\Exception;

use App\Module\Package\Contract\Exception\PackageException;
use App\Shared\Message\Message;
use App\Shared\Message\MessageList;

/**
 * A package that cannot be installed here. Every reason found is listed, each naming the
 * document (and the place in it) it comes from when it has one.
 *
 * A reason is a Message, to be read in the user's language, or a plain string: a problem the
 * package format's checker found, which is English only.
 */
final class InstallRefused extends PackageException
{
    /**
     * @param list<string|Message> $reasons
     */
    private function __construct(
        public readonly array $reasons,
    ) {
        parent::__construct(1 === \count($reasons)
            ? new Message('package.install.refused', 'The package cannot be installed: %reason%', ['reason' => $reasons[0]])
            : new Message('package.install.refused_many', 'The package cannot be installed (%count% reasons): %reasons%', ['count' => \count($reasons), 'reasons' => new MessageList($reasons)]));
    }

    /**
     * @param list<string> $problems
     */
    public static function invalid(array $problems): self
    {
        return new self($problems);
    }

    public static function alreadyInstalled(string $name, string $version): self
    {
        return new self([new Message('package.install.already_installed', '%name% is already installed, in version %version%.', ['name' => $name, 'version' => $version])]);
    }

    /**
     * @param list<string|Message> $collisions
     */
    public static function collisions(array $collisions): self
    {
        return new self($collisions);
    }

    /**
     * The same reasons, naming the package refused: one of several installed together.
     */
    public function of(string $package, string $version): self
    {
        return new self(array_map(static fn (string|Message $reason): Message => new Message('package.install.of', '%package% %version%: %reason%', ['package' => $package, 'version' => $version, 'reason' => $reason]), $this->reasons));
    }

    /**
     * A step the app refused while installing: everything done before it is rolled back.
     *
     * @param string|Message             $at      the place in the document: a YAML path, or an item
     * @param string|Message|MessageList $message why, as the app gave it
     */
    public static function at(string $path, string|Message $at, string|Message|MessageList $message): self
    {
        return new self([self::located($path, $at, $message)]);
    }

    /**
     * A reason on a place in a document: "objects/product.yaml: spec.name: …".
     */
    public static function located(string $path, string|Message $at, string|Message|MessageList $message): Message
    {
        return new Message('package.located', '%path%: %at%: %message%', ['path' => $path, 'at' => $at, 'message' => $message]);
    }
}
