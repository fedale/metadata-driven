<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Webhook;

/**
 * The tokens of the workflows on a webhook, in the current workspace: one each, kept hashed, its
 * address shown once, when made.
 */
interface WebhookTokens
{
    /**
     * A new token for the workflow, over the one it had: the old address answers no more.
     */
    public function issue(string $workflowId, ?string $by): string;

    public function has(string $workflowId): bool;

    /**
     * When it was made, and by whom; null without one.
     *
     * @return array{made_by: ?string, made_at: string}|null
     */
    public function describe(string $workflowId): ?array;

    public function matches(string $workflowId, string $token): bool;
}
