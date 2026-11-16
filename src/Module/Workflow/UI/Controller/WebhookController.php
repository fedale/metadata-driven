<?php

declare(strict_types=1);

namespace App\Module\Workflow\UI\Controller;

use App\Module\Workflow\Application\Engine\ActiveWorkflows;
use App\Module\Workflow\Application\Engine\Message\RunWebhook;
use App\Module\Workflow\Application\Engine\RunStore;
use App\Module\Workflow\Application\Webhook\WebhookTokens;
use App\Shared\Workspace\CurrentWorkspace;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * A workflow's address, for a system outside: its token in the path, checked against its hash;
 * the request's body (JSON decoded, else its text) and query go to the worker, the answer is 202.
 * A workflow not active, or not on a webhook, or a wrong token: 404, the same for all.
 */
final class WebhookController extends AbstractController
{
    /** The largest body taken: a webhook carries an event, not a file. */
    private const int MAX_BODY_BYTES = 65_536;

    public function __construct(
        private readonly ActiveWorkflows $workflows,
        private readonly WebhookTokens $tokens,
        private readonly CurrentWorkspace $workspace,
        private readonly MessageBusInterface $bus,
        private readonly RunStore $runs,
        #[Autowire(param: 'app.workflow.runs_per_minute')]
        private readonly int $runsPerMinute,
    ) {
    }

    #[Route('/hooks/{workflowId}/{token}', name: 'workflow_webhook', requirements: ['workflowId' => Requirement::UUID, 'token' => '[A-Za-z0-9_-]{20,100}'], methods: ['POST'])]
    public function receive(string $workflowId, string $token, Request $request): Response
    {
        $workflow = $this->workflows->byId($workflowId);
        if (null === $workflow || !$workflow->definition->trigger->isWebhook() || !$this->tokens->matches($workflowId, $token)) {
            throw $this->createNotFoundException('No such address.');
        }
        // A flood of requests fills no queue: past the workspace's runs a minute, refused here.
        if ($this->runs->countSince(new \DateTimeImmutable('-1 minute')) >= $this->runsPerMinute) {
            return new JsonResponse(['error' => 'Too many runs in this workspace: try again in a minute.'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '60']);
        }
        $content = $request->getContent();
        if (\strlen($content) > self::MAX_BODY_BYTES) {
            return new JsonResponse(['error' => 'The body is over 64 KB.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $body = '' === $content ? null : json_decode($content, true);
        $this->bus->dispatch(new RunWebhook($this->workspace->id(), $workflow->workflowId, $workflow->versionId, null === $body && '' !== $content ? $content : $body, $request->query->all()));

        return new JsonResponse(['queued' => true], Response::HTTP_ACCEPTED);
    }
}
