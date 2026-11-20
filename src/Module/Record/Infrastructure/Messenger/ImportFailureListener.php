<?php

declare(strict_types=1);

namespace App\Module\Record\Infrastructure\Messenger;

use App\Module\Record\Application\Import\BackgroundImports;
use App\Module\Record\Application\Import\Message\CheckImport;
use App\Module\Record\Application\Import\Message\RunImport;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * A background import whose message failed for good (the worker's retries spent, the message
 * sent to the failed transport): its job is failed, rather than left checking or importing.
 */
#[AsEventListener]
final class ImportFailureListener
{
    public function __construct(
        private readonly BackgroundImports $imports,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if ($event->willRetry() || (!$message instanceof CheckImport && !$message instanceof RunImport)) {
            return;
        }
        $this->imports->fail($message->workspaceId, $message->importId, $event->getThrowable());
    }
}
