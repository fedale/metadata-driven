<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Workflow;

use App\Module\Access\Application\PermissionSynchronizer;
use App\Module\Access\Contract\Permissions;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Engine\Message\ResumeRun;
use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\Engine\WorkflowEngine;
use App\Module\Workflow\Application\RunQueries;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition;
use App\Module\Workflow\Domain\Model\Workflow;
use App\Shared\Message\MessageTranslator;
use App\Tests\Support\CreatesRelatedObjects;
use App\Tests\Support\RunErrors;
use Doctrine\DBAL\Connection;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * A run that waits: what it did is kept, it resumes later from the step after its wait (the
 * tests' delayed transport keeps it until the test resumes it).
 */
final class WaitTest extends KernelTestCase
{
    use CreatesRelatedObjects;

    private const string LATER = <<<'YAML'
        trigger: { on: created, object: person, when: after }
        steps:
          - { key: pet, type: create_record, object: pet, values: { name: 'Pet of {{ $trigger.record.full_name }}' } }
          - { key: later, type: wait, for: 2 days }
          - { key: renamed, type: update_record, object: pet, id: '{{ pet.id }}', values: { name: 'Old pet of {{ $trigger.record.full_name }}' } }
        YAML;

    protected function setUp(): void
    {
        $container = self::getContainer();
        self::createRelatedObjects($container);
        $container->get(PermissionSynchronizer::class)->syncAll();
        $container->get(UserManagerInterface::class)->createUser('owner@example.com', 'correct horse battery staple');
        $container->get(RbacManagerInterface::class)->assign('owner@example.com', Permissions::SUPER_ADMIN_ROLE);
        $user = $container->get(UserProvider::class)->loadUserByIdentifier('owner@example.com');
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $this->queue()->reset();
    }

    public function testWaitsThenResumesFromTheStepAfterTheWait(): void
    {
        $this->activate(self::LATER);
        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada']));

        // Waiting: the pet made before the wait is kept.
        self::assertSame(['Pet of Ada'], $this->pets());
        self::assertSame('waiting', $this->runStatus());
        $sent = $this->queue()->getSent();
        self::assertCount(1, $sent);
        $delay = $sent[0]->last(DelayStamp::class)?->getDelay();
        self::assertGreaterThan(47 * 3600 * 1000, $delay);

        // Roles changed meanwhile: the resumed run reads them as they are now.
        self::getContainer()->get(RbacManagerInterface::class)->revoke('owner@example.com', Permissions::SUPER_ADMIN_ROLE);
        self::getContainer()->get(RbacManagerInterface::class)->assign('owner@example.com', 'record.pet.update');
        $run = $this->resume();
        self::assertSame(RunLog::COMPLETED, $run?->status, (string) $run?->error);
        $user = $run->chain['$user'] ?? null;
        self::assertIsArray($user);
        self::assertSame([], $user['roles']);
        self::assertSame(['Old pet of Ada'], $this->pets());
        self::assertSame('completed', $this->runStatus());
        self::assertSame([['pet', 0], ['later', 1], ['renamed', 2]], array_map(
            static fn (array $row): array => [$row['key'], $row['position']],
            self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT key, position FROM core.workflow_run_step ORDER BY position'),
        ));
    }

    public function testAVersionDeactivatedMeanwhileEndsTheRun(): void
    {
        $workflow = $this->activate(self::LATER);
        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada']));
        self::getContainer()->get(WorkflowManager::class)->deactivate($workflow);

        $run = $this->resume();

        self::assertSame([RunLog::CANCELLED, 'The version it ran is no longer active.'], [$run?->status, RunErrors::english($run?->error)]);
        self::assertSame(['Pet of Ada'], $this->pets());
    }

    public function testWhyARunFailedIsReadInTheLanguageOfItsReader(): void
    {
        $this->activate(str_replace('for: 2 days', "until: '{{ \$trigger.record.city }}'", self::LATER), 'Bad');
        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Bob', 'city' => 'Pisa']));
        $connection = self::getContainer()->get(Connection::class);
        $id = $connection->fetchOne("SELECT id FROM core.workflow_run WHERE status = 'failed'");
        self::assertIsString($id);

        $run = self::getContainer()->get(RunQueries::class)->run($id);
        self::assertNotNull($run);
        $messages = self::getContainer()->get(MessageTranslator::class);
        self::assertSame('"Pisa" non è una data fino a cui aspettare.', $messages->trans($run['error'] ?? '', 'it'));
        self::assertSame('"Pisa" non è una data fino a cui aspettare.', $messages->trans(end($run['steps'])['error'] ?? '', 'it'));

        // An error stored before errors were messages: read as it was written.
        $connection->executeStatement("UPDATE core.workflow_run SET error = to_jsonb('\"Pisa\" is no date to wait until.'::text) WHERE id = ?", [$id]);
        self::assertSame('"Pisa" is no date to wait until.', self::getContainer()->get(RunQueries::class)->run($id)['error'] ?? null);
    }

    public function testADatePassedWaitsNotAndAWrongOneFails(): void
    {
        $this->activate(str_replace('for: 2 days', "until: '2020-01-01'", self::LATER));
        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada']));
        self::assertSame(['Old pet of Ada'], $this->pets());
        self::assertSame([], $this->queue()->getSent());

        $this->activate(str_replace('for: 2 days', "until: '{{ \$trigger.record.city }}'", self::LATER), 'Bad');
        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Bob', 'city' => 'Pisa']));
        self::assertSame('"Pisa" is no date to wait until.', RunErrors::english(self::getContainer()->get(Connection::class)->fetchOne("SELECT error FROM core.workflow_run WHERE status = 'failed'")));
    }

    public function testAWaitIsAFirstStepOfAnAfterRun(): void
    {
        try {
            (new DefinitionParser())->parse(<<<'YAML'
                trigger: { on: created, object: person, when: before }
                steps:
                  - { key: now, type: wait, for: 1 day, until: '2030-01-01' }
                  - { key: check, type: if, branches: [{ steps: [{ key: inner, type: wait, for: 3 aeons }] }] }
                YAML);
            self::fail('Refused.');
        } catch (InvalidWorkflowDefinition $e) {
            self::assertEquals([
                'steps.0' => ['A wait takes for (a duration) or until (a date), one of them.'],
                'steps.0.type' => ['A write waits for no one: no wait in a workflow run before the write.'],
                'steps.1.branches.0.steps.0.for' => ['A duration: a number and minutes, hours, days or weeks, e.g. 30 minutes.'],
                'steps.1.branches.0.steps.0.type' => ['A write waits for no one: no wait in a workflow run before the write.', 'A wait is one of the first steps: not inside an if or an iterator, which it would have to resume halfway through.'],
            ], array_map(static fn (array $messages): array => array_map(strval(...), $messages), $e->errors));
        }
    }

    private function activate(string $yaml, string $name = 'Later'): Workflow
    {
        $manager = self::getContainer()->get(WorkflowManager::class);
        $workflow = $manager->create($name, null, $yaml);
        $manager->activate($workflow);

        return $workflow;
    }

    /**
     * The run waiting in the queue, resumed now.
     */
    private function resume(): ?RunLog
    {
        $sent = $this->queue()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(ResumeRun::class, $message);
        $this->queue()->reset();

        return self::getContainer()->get(WorkflowEngine::class)->resume($message);
    }

    private function queue(): InMemoryTransport
    {
        // when@test's only (config/packages/messenger.yaml).
        $transport = self::getContainer()->get('messenger.transport.delayed');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function runStatus(): mixed
    {
        return self::getContainer()->get(Connection::class)->fetchOne('SELECT status FROM core.workflow_run');
    }

    /**
     * @return list<mixed>
     *
     * @phpstan-impure the records change between two calls
     */
    private function pets(): array
    {
        return array_map(static fn (RecordOutput $pet): mixed => $pet->get('name'), iterator_to_array(self::getContainer()->get(RecordFinderInterface::class)->each('pet'), false));
    }
}
