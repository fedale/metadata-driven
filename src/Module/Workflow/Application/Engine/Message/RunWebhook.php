<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine\Message;

/**
 * A request to a workflow's address, received: its run, by the worker (RunWebhookHandler).
 */
final readonly class RunWebhook
{
    /**
     * @param mixed                $body  the request's JSON decoded, else its text
     * @param array<string, mixed> $query the address's parameters
     */
    public function __construct(
        public string $workspaceId,
        public string $workflowId,
        public string $versionId,
        public mixed $body,
        public array $query,
    ) {
    }
}
