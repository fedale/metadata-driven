<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine\Message;

use App\Module\Workflow\Application\Engine\WorkflowEngine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ResumeRunHandler
{
    public function __construct(
        private readonly WorkflowEngine $engine,
    ) {
    }

    public function __invoke(ResumeRun $message): void
    {
        $this->engine->resume($message);
    }
}
