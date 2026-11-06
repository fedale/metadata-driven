<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

use Fedale\TemplateFormat\Document;

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

    /** @var list<string> */
    public array $applied = [];

    /** @var list<array{key: string, description: string}> */
    public array $conflicts = [];

    public function write(Document $seed, int $i, ?string $id, string $what): void
    {
        $this->writes[$seed->path][$i] = $id;
        $this->applied[] = \sprintf('%s: %s', $seed->path, $what);
    }

    public function delete(Document $seed, string $objectId, string $id, string $what): void
    {
        $this->deletes[] = [$objectId, $id];
        $this->applied[] = \sprintf('%s: %s', $seed->path, $what);
    }

    public function conflict(Document $seed, string $key, string $what): void
    {
        $this->conflicts[] = ['key' => \sprintf('record:%s:%s', $seed->path, $key), 'description' => \sprintf('%s: %s', $seed->path, $what)];
    }
}
