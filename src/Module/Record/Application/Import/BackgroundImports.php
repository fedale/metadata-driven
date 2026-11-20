<?php

declare(strict_types=1);

namespace App\Module\Record\Application\Import;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\RecordAction;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Notification\Contract\Dto\NotificationMessage;
use App\Module\Notification\Contract\NotifierInterface;
use App\Module\Record\Application\Import\Message\CheckImport;
use App\Module\Record\Application\Import\Message\RunImport;
use App\Module\Record\Domain\Exception\ImportRefused;
use App\Module\Record\Domain\Model\ImportJob;
use App\Module\Record\Domain\Model\ImportJobStatus;
use App\Module\Record\Domain\Repository\ImportJobRepository;
use App\Module\User\Contract\ActingUserInterface;
use App\Module\User\Contract\Exception\NotAMember;
use App\Shared\Csv\CsvFile;
use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\Workspaces;
use App\Shared\Workspace\WorkspaceSessions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The imports of files too large for a request (see docs/import.md): the wizard queues them, the
 * worker checks them, then imports them, batch by batch, as their creator (ActingUserInterface),
 * in their workspace.
 *
 * A check rolls every batch back. An import commits every batch with the job's progress, in one
 * transaction: a worker retried after a crash goes on after the last batch written, never writes
 * one twice. A batch refused at import time stops the import there; the batches before it stay.
 *
 * The job's file is a file of its own, <jobs directory>/<id>.csv, deleted when the job ends; a
 * checked or refused job keeps it, for its import or its columns.
 */
final class BackgroundImports
{
    /** A job file this old is deleted when a new job is queued: its job will not be imported. */
    private const int MAX_AGE = 7 * 86400;

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly RecordImporter $importer,
        private readonly MetadataRegistryInterface $metadata,
        private readonly AccessCheckerInterface $access,
        private readonly ActingUserInterface $actingUser,
        private readonly NotifierInterface $notifier,
        private readonly MessageBusInterface $bus,
        private readonly CurrentWorkspace $workspace,
        private readonly Workspaces $workspaces,
        private readonly WorkspaceSessions $sessions,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.import.drafts_dir%/jobs')]
        private readonly string $directory,
        #[Autowire('%app.import.batch_size%')]
        private readonly int $batchSize,
    ) {
    }

    /**
     * Makes a job of a file and the wizard's choices, and queues its check. The file is moved: it
     * is the job's now.
     *
     * @param array<string, string|null> $mapping column of the file => the field it fills; null: ignored
     * @param array<string, string>      $matches relation field => the unique field of its target its cells hold
     */
    public function queueCheck(string $object, string $file, string $originalName, string $separator, array $mapping, ?string $key, array $matches, int $rows, string $createdBy, string $locale): ImportJob
    {
        $this->deleteAbandoned();
        $job = new ImportJob(Uuid::v4()->toRfc4122(), $object, $originalName, $separator, $mapping, $key, $matches, $createdBy, $locale, $rows, new \DateTimeImmutable());
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o770, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(\sprintf('The directory "%s" cannot be created.', $this->directory));
        }
        if (!rename($file, $this->path($job))) {
            throw new \RuntimeException(\sprintf('The file "%s" cannot be moved to "%s".', $file, $this->path($job)));
        }
        $this->jobs->add($job);
        $this->bus->dispatch(new CheckImport($this->workspace->id(), $job->id));

        return $job;
    }

    /**
     * Queues the import of a checked job.
     *
     * @return bool false: the job is not checked (any more), or its file is gone
     */
    public function queueImport(ImportJob $job): bool
    {
        if (!$this->hasFile($job) || !$this->jobs->transition($job, ImportJobStatus::Checked, ImportJobStatus::Importing)) {
            return false;
        }
        // The counts were the check's: the import counts from the first row again.
        $job->restart();
        $this->jobs->save($job);
        $this->bus->dispatch(new RunImport($this->workspace->id(), $job->id));

        return true;
    }

    /**
     * Asks the worker to stop a job before its next batch; an import keeps the batches it wrote.
     */
    public function stop(ImportJob $job): void
    {
        if ($job->status->isRunning()) {
            $this->jobs->requestStop($job);
        }
    }

    /**
     * Gives a checked or refused job's file back to the wizard, at $draftFile, and deletes the
     * job: its columns are chosen again.
     *
     * @return bool false: the job is in another state, or its file is gone
     */
    public function release(ImportJob $job, string $draftFile): bool
    {
        if (!\in_array($job->status, [ImportJobStatus::Checked, ImportJobStatus::Refused], true) || !$this->hasFile($job) || !rename($this->path($job), $draftFile)) {
            return false;
        }
        $this->jobs->delete($job);

        return true;
    }

    public function hasFile(ImportJob $job): bool
    {
        return is_file($this->path($job));
    }

    /**
     * The worker's: the check of a job, every batch rolled back.
     */
    public function check(string $workspaceId, string $id): void
    {
        $this->inJob($workspaceId, $id, function (ImportJob $job): void {
            if (ImportJobStatus::Checking !== $job->status) {
                return;
            }
            // Run again after a crash: from the first row.
            $job->restart();
            $this->jobs->save($job);
            if (!$this->mayGoOn($job)) {
                return;
            }
            try {
                $plan = $this->importer->prepare($job->object, $job->fields(), $job->key, $job->usedMatches());
                $job->addReasons([...$plan->problems, ...$this->importer->keyProblems($plan, $this->rows($job))]);
                if ($job->reasonCount > 0) {
                    $this->finish($job, ImportJobStatus::Refused);

                    return;
                }
                foreach ($this->batches($job, 0) as $rows) {
                    if ($this->jobs->stopRequested($job->id)) {
                        $this->finish($job, ImportJobStatus::Cancelled);

                        return;
                    }
                    $batch = $this->importer->importBatch($plan, $rows, true);
                    $job->created += $batch->created;
                    $job->updated += $batch->updated;
                    $job->processed += \count($rows);
                    $job->addReasons($batch->reasons);
                    $this->jobs->save($job);
                }
            } catch (ImportRefused $e) {
                $job->addReasons($e->reasons);
            } catch (\UnexpectedValueException $e) {
                // The file: a line that does not fit, or is not UTF-8.
                $job->addReasons([$e->getMessage()]);
            }
            $this->finish($job, $job->reasonCount > 0 ? ImportJobStatus::Refused : ImportJobStatus::Checked);
        });
    }

    /**
     * The worker's: the import of a checked job, every batch committed with the job's progress.
     */
    public function import(string $workspaceId, string $id): void
    {
        $this->inJob($workspaceId, $id, function (ImportJob $job): void {
            if (ImportJobStatus::Importing !== $job->status || !$this->mayGoOn($job)) {
                return;
            }
            try {
                $plan = $this->importer->prepare($job->object, $job->fields(), $job->key, $job->usedMatches());
                if ([] !== $plan->problems) {
                    // The object changed since the check: nothing more is written.
                    throw new ImportRefused($plan->problems);
                }
                // A worker retried goes on after the batches already written.
                foreach ($this->batches($job, $job->processed) as $rows) {
                    if ($this->jobs->stopRequested($job->id)) {
                        $this->finish($job, ImportJobStatus::Cancelled);

                        return;
                    }
                    $batch = $this->importer->importBatch($plan, $rows, false, function (ImportBatch $batch) use ($job, $rows): void {
                        $job->created += $batch->created;
                        $job->updated += $batch->updated;
                        $job->processed += \count($rows);
                        $this->jobs->save($job);
                    });
                    if ([] !== $batch->reasons) {
                        $job->addReasons($batch->reasons);
                        $job->stoppedAtLine = $rows[0]['line'];
                        $this->finish($job, ImportJobStatus::Stopped);

                        return;
                    }
                }
            } catch (ImportRefused $e) {
                $job->addReasons($e->reasons);
            } catch (\UnexpectedValueException $e) {
                // The file changed since its check.
                $job->addReasons([$e->getMessage()]);
            }
            $this->finish($job, $job->reasonCount > 0 ? ImportJobStatus::Stopped : ImportJobStatus::Imported);
        });
    }

    /**
     * The worker's, once its retries are spent: an error outside the rows (the database down)
     * fails the job. The batches an import committed stay.
     */
    public function fail(string $workspaceId, string $id, \Throwable $error): void
    {
        $this->logger->error('The background import {id} failed: {message}', ['id' => $id, 'message' => $error->getMessage(), 'exception' => $error]);
        $this->inWorkspace($workspaceId, function () use ($id): void {
            $job = $this->jobs->get($id);
            if (null === $job || !$job->status->isRunning()) {
                return;
            }
            $job->addReasons(['An error stopped it: the details are in the log of the application.']);
            $this->finish($job, ImportJobStatus::Failed);
        });
    }

    /**
     * The rows of the job's file, with its columns named after their fields.
     *
     * @return \Generator<int, array{line: int, values: array<string, string|null>}>
     */
    private function rows(ImportJob $job): \Generator
    {
        return ImportMapping::rename(CsvFile::rows($this->path($job), $job->separator), $job->mapping);
    }

    /**
     * @return \Generator<int, list<array{line: int, values: array<string, string|null>}>>
     */
    private function batches(ImportJob $job, int $skip): \Generator
    {
        $batch = [];
        foreach ($this->rows($job) as $row) {
            if ($skip > 0) {
                --$skip;
                continue;
            }
            $batch[] = $row;
            if (\count($batch) >= max(1, $this->batchSize)) {
                yield $batch;
                $batch = [];
            }
        }
        if ([] !== $batch) {
            yield $batch;
        }
    }

    /**
     * Whether the job may run: not stopped meanwhile, its file still there, its creator still
     * allowed to import what it fills. A job that may not is ended.
     */
    private function mayGoOn(ImportJob $job): bool
    {
        if ($this->jobs->stopRequested($job->id)) {
            $this->finish($job, ImportJobStatus::Cancelled);

            return false;
        }
        $problem = !$this->hasFile($job) ? \sprintf('The file "%s" is gone: upload it again.', $job->originalName) : $this->permissionProblem($job);
        if (null !== $problem) {
            $job->addReasons([$problem]);
            $this->finish($job, ImportJobStatus::Failed);

            return false;
        }

        return true;
    }

    /**
     * The permissions the wizard checked when the job was queued, checked again: they may have
     * changed since.
     */
    private function permissionProblem(ImportJob $job): ?string
    {
        try {
            $object = $this->metadata->getObject($job->object);
        } catch (MetadataException $e) {
            return $e->getMessage();
        }
        if (!$this->access->canRecords($object->name, RecordAction::Create)) {
            return \sprintf('No permission to create the records of "%s".', $object->name);
        }
        if (null !== $job->key && !$this->access->canRecords($object->name, RecordAction::Update)) {
            return \sprintf('No permission to update the records of "%s": import without a key.', $object->name);
        }
        foreach ($job->fields() as $name) {
            $target = ($object->fields[$name] ?? null)?->target();
            if (null !== $target && !$this->access->canRecords($target, RecordAction::Read)) {
                return \sprintf('No permission to read the records of "%s", which %s links to.', $target, $name);
            }
        }

        return null;
    }

    /**
     * Ends the job (or ends its check), tells its creator, and deletes its file once nothing more
     * can be done with it.
     */
    private function finish(ImportJob $job, ImportJobStatus $status): void
    {
        $job->status = $status;
        $this->jobs->save($job);
        if ($status->isFinal() && $this->hasFile($job)) {
            unlink($this->path($job));
        }
        if (ImportJobStatus::Cancelled !== $status) {
            $this->notify($job);
        }
    }

    private function notify(ImportJob $job): void
    {
        $parameters = [
            '%file%' => $job->originalName,
            '%created%' => $job->created,
            '%updated%' => $job->updated,
            '%count%' => $job->reasonCount,
            '%line%' => $job->stoppedAtLine ?? 0,
        ];
        $trans = fn (string $key): string => $this->translator->trans($key, $parameters, null, $job->locale);
        try {
            $this->notifier->notify([$job->createdBy], new NotificationMessage(
                $trans('import.notify.'.$job->status->value.'.title'),
                // Stopped before any batch (the object or the file changed since the check): no
                // line to name.
                $trans('import.notify.'.$job->status->value.(ImportJobStatus::Stopped === $job->status && null === $job->stoppedAtLine ? '.text_unlined' : '.text')),
                $this->urls->generate('record_import_job', ['object' => $job->object, 'job' => $job->id]),
                $trans('import.notify.origin'),
            ));
        } catch (\Throwable $e) {
            // The job's outcome is kept and shown on its page: a notification lost is not worth
            // failing it for.
            $this->logger->warning('The notification of the background import {id} was not sent: {message}', ['id' => $job->id, 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * Runs $work on the job, in its workspace, as its creator; a creator no longer a member fails
     * it. A job deleted meanwhile (its workspace too) is nothing to run.
     *
     * @param callable(ImportJob): void $work
     */
    private function inJob(string $workspaceId, string $id, callable $work): void
    {
        $this->inWorkspace($workspaceId, function () use ($id, $work): void {
            $job = $this->jobs->get($id);
            if (null === $job) {
                return;
            }
            try {
                $this->actingUser->actAs($job->createdBy, static fn () => $work($job));
            } catch (NotAMember $e) {
                $job->addReasons([$e->getMessage()]);
                $this->finish($job, ImportJobStatus::Failed);
            }
        });
    }

    /**
     * @param callable(): void $work
     */
    private function inWorkspace(string $workspaceId, callable $work): void
    {
        if ($this->workspace->isSelected() && $this->workspace->id() === $workspaceId) {
            $work();

            return;
        }
        $workspace = $this->workspaces->byId($workspaceId);
        if (null !== $workspace) {
            $this->sessions->within($workspace, $work);
        }
    }

    private function path(ImportJob $job): string
    {
        return $this->directory.'/'.$job->id.'.csv';
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
