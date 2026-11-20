<?php

declare(strict_types=1);

namespace App\Module\Package\Application\Upgrade;

use App\Shared\Message\Message;
use Fedale\PackageFormat\Document;

/**
 * The record changes SeedPlanner found: the rows to write, the records to delete, the conflicts.
 *
 * @internal
 */
final class SeedChanges
{
    /** @var array<string, array<int, string|null>> seed path => row index => the id of the record it updates, null to create */
    public array $writes = [];

    /** @var list<array{string, string}> [object id, record id]: the name may change in the same upgrade */
    public array $deletes = [];

    /** @var list<Message> "seeds/product.yaml: create record OSL-01" */
    public array $applied = [];

    /** @var list<array{key: string, description: Message}> */
    public array $conflicts = [];

    public function write(Document $seed, int $i, ?string $id, Message $what): void
    {
        $this->writes[$seed->path][$i] = $id;
        $this->applied[] = self::in($seed, $what);
    }

    public function delete(Document $seed, string $objectId, string $id, Message $what): void
    {
        $this->deletes[] = [$objectId, $id];
        $this->applied[] = self::in($seed, $what);
    }

    public function conflict(Document $seed, string $key, Message $what): void
    {
        $this->conflicts[] = ['key' => \sprintf('record:%s:%s', $seed->path, $key), 'description' => self::in($seed, $what)];
    }

    private static function in(Document $seed, Message $what): Message
    {
        return new Message('package.upgrade.in_seed', '%path%: %change%', ['path' => $seed->path, 'change' => $what]);
    }
}
