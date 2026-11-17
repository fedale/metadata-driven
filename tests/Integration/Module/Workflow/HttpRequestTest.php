<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Workflow;

use App\Module\Access\Application\PermissionSynchronizer;
use App\Module\Access\Contract\Permissions;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Engine\StepFailed;
use App\Module\Workflow\Application\Engine\WorkflowEngine;
use App\Module\Workflow\Application\Engine\WorkflowHttp;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition;
use App\Module\Workflow\Domain\Repository\WorkflowRepository;
use App\Tests\Support\CreatesRelatedObjects;
use App\Tests\Support\FakePackageServer;
use Doctrine\DBAL\Connection;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * A workflow's requests to the outside: sent with the run's data, their answer read, tried again
 * on a 5xx; never to a private network.
 */
final class HttpRequestTest extends KernelTestCase
{
    use CreatesRelatedObjects;

    private const string HOOK = <<<'YAML'
        trigger: { on: created, object: person, when: after }
        steps:
          - key: hook
            type: http_request
            method: post
            url: 'https://api.example.test/people/{{ $trigger.record.id }}'
            headers: { X-Workspace: '{{ $workspace.slug }}' }
            body: { name: '{{ $trigger.record.full_name }}', city: '{{ $trigger.record.city }}' }
            retries: 2
          - { key: keep, type: update_record, object: person, id: '{{ $trigger.record.id }}', values: { city: 'Ticket {{ hook.body.ticket }}' }, act_as: ROLE_SUPER_ADMIN }
        YAML;

    protected function setUp(): void
    {
        FakePackageServer::reset();
        $container = self::getContainer();
        self::createRelatedObjects($container);
        $container->get(PermissionSynchronizer::class)->syncAll();
        $container->get(UserManagerInterface::class)->createUser('owner@example.com', 'correct horse battery staple');
        $container->get(RbacManagerInterface::class)->assign('owner@example.com', Permissions::SUPER_ADMIN_ROLE);
        $user = $container->get(UserProvider::class)->loadUserByIdentifier('owner@example.com');
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    public function testSendsTheRunsDataAndReadsTheAnswerTriedAgainOnA5xx(): void
    {
        $answers = [new MockResponse('down', ['http_code' => 503]), new MockResponse('{"ticket": 42}', ['http_code' => 201, 'response_headers' => ['content-type' => 'application/json']])];
        $this->answerEverything(static function () use (&$answers): MockResponse {
            return array_shift($answers) ?? new MockResponse('', ['http_code' => 500]);
        });
        $this->activate(self::HOOK);

        $ada = self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada', 'city' => 'Pisa']));

        self::assertSame('completed', $this->runStatus(), json_encode($this->runError()) ?: '');
        $requests = FakePackageServer::requests();
        self::assertCount(2, $requests);
        [$method, $url, $options] = $requests[1];
        self::assertSame(['POST', 'https://api.example.test/people/'.$ada->id], [$method, $url]);
        self::assertIsString($options['body']);
        self::assertEquals(['name' => 'Ada', 'city' => 'Pisa'], json_decode($options['body'], true));
        self::assertIsArray($options['headers']);
        self::assertContains('X-Workspace: default', $options['headers']);
        self::assertSame('Ticket 42', self::getContainer()->get(RecordManagerInterface::class)->find('person', $ada->id)?->get('city'));
    }

    public function testAnErrorStatusFailsTheStepUnlessToldNot(): void
    {
        $this->answerEverything(static fn (): MockResponse => new MockResponse('nope', ['http_code' => 404]));
        $this->activate(self::HOOK);
        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada']));
        self::assertSame('failed', $this->runStatus());
        $error = $this->runError();
        self::assertIsString($error);
        self::assertStringEndsWith('answered 404.', $error);

        $this->activate("trigger: { on: created, object: person, when: after }\nsteps: [{ key: hook, type: http_request, url: 'https://api.example.test/x', fail_on_error: false }]", 'Lenient');
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM core.workflow_run');
        self::getContainer()->get(WorkflowManager::class)->deactivate(self::getContainer()->get(WorkflowRepository::class)->findByName('Hook') ?? throw new \LogicException());
        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Bob']));
        self::assertSame('completed', $this->runStatus());
    }

    public function testATestOnARecordSendsNothing(): void
    {
        $this->answerEverything(static fn (): MockResponse => new MockResponse('{"ticket": 42}', ['http_code' => 201, 'response_headers' => ['content-type' => 'application/json']]));
        $ada = self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada', 'city' => 'Pisa']));

        $run = self::getContainer()->get(WorkflowEngine::class)->dryRun((new DefinitionParser())->parse(self::HOOK), 'Hook', $ada->id);

        // A request cannot be rolled back: the test shows it as it would go, and sends nothing.
        self::assertSame('completed', $run->status, (string) $run->error);
        self::assertSame([], FakePackageServer::requests());
        self::assertSame(
            ['method' => 'POST', 'url' => 'https://api.example.test/people/'.$ada->id, 'headers' => ['X-Workspace' => 'default'], 'body' => ['name' => 'Ada', 'city' => 'Pisa']],
            $run->steps[0]['input'],
        );
        self::assertSame(['sent' => false, 'status' => null, 'headers' => [], 'body' => null], $run->steps[0]['output']);
        // The steps after it read no answer.
        self::assertIsArray($run->steps[1]['output']);
        self::assertSame('Ticket', $run->steps[1]['output']['city'] ?? null);
    }

    public function testNeverAPrivateNetworkNorAnotherScheme(): void
    {
        // As configured outside the tests: private networks refused.
        $http = new WorkflowHttp(new MockHttpClient(new MockResponse('{}')), allowPrivateNetworks: false, retryDelayMs: 0);
        foreach (['http://127.0.0.1/admin', 'http://169.254.169.254/latest/meta-data', 'http://[::1]/'] as $address) {
            try {
                $http->send('GET', $address, [], 0);
                self::fail(\sprintf('%s must be refused.', $address));
            } catch (StepFailed $e) {
                self::assertStringContainsString('is blocked', $e->getMessage());
            }
        }
        try {
            $http->send('GET', 'file:///etc/passwd', [], 0);
            self::fail('Refused.');
        } catch (StepFailed $e) {
            self::assertSame('"file:///etc/passwd" is no http or https address.', $e->getMessage());
        }
    }

    public function testAnAnswerOverOneMegabyteStopsThere(): void
    {
        // No length announced: the body comes in chunks, and the reading stops past the cap.
        $chunks = static function (): \Generator {
            for ($i = 0; $i < 2048; ++$i) {
                yield str_repeat('x', 1024);
            }
        };
        $http = new WorkflowHttp(new MockHttpClient(new MockResponse($chunks())), allowPrivateNetworks: true, retryDelayMs: 0);

        try {
            $http->send('GET', 'https://api.example.test/huge', [], 0);
            self::fail('Refused.');
        } catch (StepFailed $e) {
            self::assertSame('The response of https://api.example.test/huge is over 1 MB.', $e->getMessage());
        }
    }

    public function testNoRequestInAWrite(): void
    {
        try {
            (new DefinitionParser())->parse("trigger: { on: created, object: person, when: before }\nsteps: [{ key: hook, type: http_request, url: 'https://x.test', method: fetch, timeout: 90 }]");
            self::fail('Refused.');
        } catch (InvalidWorkflowDefinition $e) {
            self::assertSame([
                'steps.0.type' => ['No call to the outside in a write: no http_request in a workflow run before the write.'],
                'steps.0.method' => ['One of GET, POST, PUT, PATCH, DELETE.'],
                'steps.0.timeout' => ['Seconds, from 1 to 30.'],
            ], $e->errors);
        }
    }

    /**
     * @param callable(): MockResponse $answer
     */
    private function answerEverything(callable $answer): void
    {
        foreach (['https://api.example.test/x'] as $url) {
            FakePackageServer::answer($url, $answer);
        }
        FakePackageServer::answerAny($answer);
    }

    private function activate(string $yaml, string $name = 'Hook'): void
    {
        $manager = self::getContainer()->get(WorkflowManager::class);
        $manager->activate($manager->create($name, null, $yaml));
    }

    private function runStatus(): mixed
    {
        return self::getContainer()->get(Connection::class)->fetchOne('SELECT status FROM core.workflow_run ORDER BY started_at DESC LIMIT 1');
    }

    private function runError(): mixed
    {
        return self::getContainer()->get(Connection::class)->fetchOne('SELECT error FROM core.workflow_run ORDER BY started_at DESC LIMIT 1');
    }
}
