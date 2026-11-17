<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Workflow;

use App\Module\Access\Application\PermissionSynchronizer;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Module\Workflow\Application\Engine\ActiveWorkflows;
use App\Module\Workflow\Application\Engine\Message\RunWorkflow;
use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\Engine\RunStore;
use App\Module\Workflow\Application\Engine\WorkflowEngine;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Domain\Model\Workflow;
use App\Shared\Workspace\CurrentWorkspace;
use App\Tests\Support\CreatesRelatedObjects;
use Doctrine\DBAL\Connection;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * After-runs from the queue, as the worker runs them: no one signed in, the version that was
 * active, the runs a minute.
 */
final class BackgroundRunsTest extends KernelTestCase
{
    use CreatesRelatedObjects;

    private const string WELCOME = "trigger: { on: created, object: person, when: after }\nsteps: [{ key: pet, type: create_record, object: pet, values: { name: 'Pet of {{ \$trigger.record.full_name }}' } }]";

    private Workflow $workflow;

    protected function setUp(): void
    {
        $container = self::getContainer();
        self::createRelatedObjects($container);
        $container->get(PermissionSynchronizer::class)->syncAll();
        $rbac = $container->get(RbacManagerInterface::class);
        foreach (['owner@example.com', 'clerk@example.com'] as $email) {
            $container->get(UserManagerInterface::class)->createUser($email, 'correct horse battery staple');
        }
        foreach (['person.create', 'pet.create'] as $permission) {
            $rbac->assign('owner@example.com', 'record.'.$permission);
        }
        $rbac->assign('clerk@example.com', 'record.person.create');
        $this->signIn('owner@example.com');
        $manager = $container->get(WorkflowManager::class);
        $this->workflow = $manager->create('Welcome', null, self::WELCOME);
        $manager->activate($this->workflow);
    }

    public function testRunsInTheWorkerAsWhoeverTriggeredIt(): void
    {
        // No one signed in, as in the worker: the owner's permissions, from their assignments.
        self::getContainer()->get(TokenStorageInterface::class)->setToken(null);
        $run = $this->engine()->runQueued($this->message('owner@example.com'));
        self::assertSame(RunLog::COMPLETED, $run?->status, (string) $run?->error);
        self::assertSame('owner@example.com', $run->triggeredBy);
        self::assertSame(['Pet of Ada'], $this->pets());

        // The clerk may not create pets: neither may the step, as the clerk.
        $run = $this->engine()->runQueued($this->message('clerk@example.com'));
        self::assertSame([RunLog::FAILED, 'clerk@example.com does not hold record.pet.create.'], [$run?->status, $run?->error]);
    }

    public function testARefuseKeepsNothingOfTheRun(): void
    {
        $manager = self::getContainer()->get(WorkflowManager::class);
        $strict = $manager->create('Strict', null, <<<'YAML'
            trigger: { on: created, object: person, when: after }
            steps:
              - { key: pet, type: create_record, object: pet, values: { name: 'Pet of {{ $trigger.record.full_name }}' } }
              - { key: no, type: refuse, message: 'No pet for {{ $trigger.record.full_name }}.' }
            YAML);
        $manager->activate($strict);

        $run = $this->engine()->runQueued($this->message('owner@example.com', $strict));

        // The pet was written before the refuse: rolled back with the run, which is kept as failed.
        self::assertSame([RunLog::FAILED, 'No pet for Ada.'], [$run?->status, $run?->error]);
        self::assertSame([], $this->pets());
        self::assertSame(['failed'], self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT status FROM core.workflow_run'));
    }

    public function testAVersionNoLongerActiveRunsNoMore(): void
    {
        $message = $this->message('owner@example.com');
        self::getContainer()->get(WorkflowManager::class)->deactivate($this->workflow);

        self::assertNull($this->engine()->runQueued($message));
        self::assertSame([], $this->pets());
    }

    public function testPastTheRunsAMinuteItWaitsInTheQueueThenFails(): void
    {
        $limit = self::getContainer()->getParameter('app.workflow.runs_per_minute');
        $active = self::getContainer()->get(ActiveWorkflows::class)->byId($this->workflow->getId()->toRfc4122());
        self::assertNotNull($active);
        for ($i = 0; $i < $limit; ++$i) {
            $run = new RunLog($active, 'person', null, 'create', 'after', null, 1, new \DateTimeImmutable());
            $run->end(RunLog::COMPLETED);
            self::getContainer()->get(RunStore::class)->save($run);
        }

        // Back to the queue: the tests' transport hands it back at once, until it gives up.
        self::assertNull($this->engine()->runQueued($this->message('owner@example.com')));

        $failed = self::getContainer()->get(Connection::class)->fetchAllAssociative("SELECT error FROM core.workflow_run WHERE status = 'failed'");
        self::assertSame([['error' => 'Too many workflow runs in this workspace: at most '.$limit.' a minute.']], $failed);
        self::assertSame([], $this->pets());
    }

    public function testAWriteQueuesItsAfterRuns(): void
    {
        // The tests' transport runs it at once; the run is the queue's, as whoever wrote.
        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Bea']));

        self::assertSame(['Pet of Bea'], $this->pets());
        self::assertSame('owner@example.com', self::getContainer()->get(Connection::class)->fetchOne('SELECT triggered_by FROM core.workflow_run'));
    }

    private function message(string $triggeredBy, ?Workflow $workflow = null): RunWorkflow
    {
        $active = self::getContainer()->get(ActiveWorkflows::class)->byId(($workflow ?? $this->workflow)->getId()->toRfc4122());
        self::assertNotNull($active);

        return new RunWorkflow(
            self::getContainer()->get(CurrentWorkspace::class)->id(),
            $active->workflowId,
            $active->versionId,
            'person',
            null,
            'create',
            ['object' => 'person', 'action' => 'create', 'record' => ['id' => null, 'full_name' => 'Ada'], 'before' => null, 'changed' => ['full_name']],
            $triggeredBy,
            1,
        );
    }

    private function engine(): WorkflowEngine
    {
        return self::getContainer()->get(WorkflowEngine::class);
    }

    private function signIn(string $email): void
    {
        $user = self::getContainer()->get(UserProvider::class)->loadUserByIdentifier($email);
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
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
