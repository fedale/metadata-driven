<?php

declare(strict_types=1);

namespace App\Module\Record\UI\Import;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * The drafts of the import wizard: each file uploaded is stored as <id>.csv in the drafts
 * directory, and its draft in the user's session. A request names a draft by its id only, never
 * by a path, and finds it only in its own session, for the object it was uploaded for.
 *
 * The session is read per call, never kept: the service outlives the request in a worker.
 */
final class ImportDrafts
{
    private const string SESSION_KEY = 'record_import_drafts';
    /** Files older than this are abandoned drafts: deleted when a new one is stored. */
    private const int MAX_AGE = 86400;

    public function __construct(
        private readonly RequestStack $requests,
        #[Autowire('%app.import.drafts_dir%')]
        private readonly string $directory,
    ) {
    }

    public function create(string $object, UploadedFile $file, string $separator): ImportDraft
    {
        $this->deleteAbandoned();
        $draft = new ImportDraft(Uuid::v4()->toRfc4122(), $object, $file->getClientOriginalName(), $separator);
        $file->move($this->directory, $draft->id.'.csv');
        $this->save($draft);

        return $draft;
    }

    public function get(string $object, string $id): ?ImportDraft
    {
        $data = $this->all()[$id] ?? null;
        if (!\is_array($data)) {
            return null;
        }
        $draft = ImportDraft::fromArray($data);

        return $draft->object === $object && is_file($this->path($draft)) ? $draft : null;
    }

    public function save(ImportDraft $draft): void
    {
        $this->requests->getSession()->set(self::SESSION_KEY, [...$this->all(), $draft->id => $draft->toArray()]);
    }

    public function delete(ImportDraft $draft): void
    {
        $drafts = $this->all();
        unset($drafts[$draft->id]);
        $this->requests->getSession()->set(self::SESSION_KEY, $drafts);
        if (is_file($this->path($draft))) {
            unlink($this->path($draft));
        }
    }

    public function path(ImportDraft $draft): string
    {
        return $this->directory.'/'.$draft->id.'.csv';
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        $stored = $this->requests->getSession()->get(self::SESSION_KEY, []);
        $drafts = [];
        foreach (\is_array($stored) ? $stored : [] as $id => $draft) {
            $drafts[(string) $id] = $draft;
        }

        return $drafts;
    }

    private function deleteAbandoned(): void
    {
        foreach (glob($this->directory.'/*.csv') ?: [] as $file) {
            $modified = filemtime($file);
            if (false !== $modified && $modified < time() - self::MAX_AGE) {
                unlink($file);
            }
        }
    }
}
