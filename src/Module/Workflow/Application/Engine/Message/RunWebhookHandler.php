<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine\Message;

use App\Module\Workflow\Application\Engine\WorkflowEngine;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunWebhookHandler
{
    public function __construct(
        private readonly WorkflowEngine $engine,
    ) {
    }

    public function __invoke(RunWebhook $message): void
    {
        $this->engine->runWebhook($message);
    }
}
