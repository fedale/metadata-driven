<?php

declare(strict_types=1);

namespace App\Module\Record\Application\Import\Message;

use App\Module\Record\Application\Import\BackgroundImports;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class ImportHandler
{
    public function __construct(
        private readonly BackgroundImports $imports,
    ) {
    }

    #[AsMessageHandler]
    public function check(CheckImport $message): void
    {
        $this->imports->check($message->workspaceId, $message->importId);
    }

    #[AsMessageHandler]
    public function import(RunImport $message): void
    {
        $this->imports->import($message->workspaceId, $message->importId);
    }
}
