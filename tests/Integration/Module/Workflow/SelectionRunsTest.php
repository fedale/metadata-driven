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
use App\Module\Workflow\Application\Engine\ActiveWorkflow;
use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\Engine\RunStore;
use App\Module\Workflow\Application\ManualRunRefused;
use App\Module\Workflow\Application\ManualRuns;
use App\Module\Workflow\Application\WorkflowManager;
use App\Tests\Support\CreatesRelatedObjects;
use App\Tests\Support\RunErrors;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Manual workflows on a selection of records: a run per record, or one run on them all. The owner
 * activates and runs them, a super admin; the clerk reads nothing.
 */
final class SelectionRunsTest extends KernelTestCase
{
    use CreatesRelatedObjects;

    /** @var array<string, string> name => id */
    private array $people = [];

    protected function setUp(): void
    {
        $container = self::getContainer();
        self::createRelatedObjects($container);
        $container->get(PermissionSynchronizer::class)->syncAll();
        $rbac = $container->get(RbacManagerInterface::class);
        foreach (['owner@example.com', 'clerk@example.com'] as $email) {
            $container->get(UserManagerInterface::class)->createUser($email, 'correct horse battery staple', 'Ada', 'Lovelace');
        }
        foreach (['person', 'pet'] as $object) {
            foreach (['read', 'create', 'update', 'delete'] as $action) {
                $rbac->assign('owner@example.com', 'record.'.$object.'.'.$action);
            }
        }
        // The super admin holds every workflow's run permission, and may give any role.
        $rbac->assign('owner@example.com', Permissions::SUPER_ADMIN_ROLE);
        $this->signIn('owner@example.com');
        foreach (['Ada' => 'Pisa', 'Bob' => 'Roma', 'Carla' => 'Pisa'] as $name => $city) {
            $this->people[$name] = $container->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => $name, 'city' => $city]))->id;
        }
    }

    public function testEachRecordHasItsOwnRunAFailureStoppingNoOther(): void
    {
        $workflow = $this->workflow(<<<'YAML'
            trigger: { on: manual, object: person, label: Give a pet, selection: each }
            steps:
              - { key: pet, type: create_record, object: pet, values: { name: 'Pet of {{ $trigger.record.full_name }}' } }
              - { key: not_roma, type: if, branches: [{ when: { field: $trigger.record.city, op: eq, value: Roma }, steps: [{ key: no, type: refuse, message: 'Not in {{ $trigger.record.city }}.' }] }] }
            YAML);

        $runs = $this->runs()->runOnSelection($workflow, array_values($this->people), []);

        self::assertSame([RunLog::COMPLETED, RunLog::FAILED, RunLog::COMPLETED], array_map(static fn (RunLog $run): string => $run->status, $runs));
        self::assertSame('Not in Roma.', RunErrors::english($runs[1]->error));
        self::assertSame(['Pet of Ada', 'Pet of Carla'], $this->pets());
        // On a record's page too.
        self::assertSame(['Give a pet'], array_map(static fn (ActiveWorkflow $active): ?string => $active->definition->trigger->label, $this->runs()->runnableOnRecord('person')));
    }

    public function testOneRunOnThemAllIsAllOrNothing(): void
    {
        $workflow = $this->workflow(<<<'YAML'
            trigger: { on: manual, object: person, label: Give pets, selection: all }
            steps:
              - key: each
                type: iterator
                items: '{{ $trigger.records }}'
                steps:
                  - { key: pet, type: create_record, object: pet, values: { name: '{{ each.index }}. {{ each.item.full_name }}' } }
            YAML);

        $runs = $this->runs()->runOnSelection($workflow, [$this->people['Carla'], $this->people['Ada']], []);

        self::assertCount(1, $runs);
        self::assertSame(RunLog::COMPLETED, $runs[0]->status, (string) $runs[0]->error);
        self::assertSame(['0. Carla', '1. Ada'], $this->pets());
        // Not on a record's page: it runs on a selection only.
        self::assertSame([], $this->runs()->runnableOnRecord('person'));
        self::assertSame(['Give pets'], array_map(static fn (ActiveWorkflow $active): ?string => $active->definition->trigger->label, $this->runs()->runnableOnSelection('person')));
    }

    public function testAnIdInCapitalsIsTheSameRecord(): void
    {
        $workflow = $this->workflow("trigger: { on: manual, object: person, label: Give a pet, selection: each }\nsteps: [{ key: pet, type: create_record, object: pet, values: { name: 'Pet of {{ \$trigger.record.full_name }}' } }]");

        $runs = $this->runs()->runOnSelection($workflow, [strtoupper($this->people['Ada']), $this->people['Ada']], []);

        self::assertSame([RunLog::COMPLETED], array_map(static fn (RunLog $run): string => $run->status, $runs));
        self::assertSame(['Pet of Ada'], $this->pets());
    }

    public function testARunEachIsRefusedWholePastTheRunsOfTheMinute(): void
    {
        $workflow = $this->workflow("trigger: { on: manual, object: person, label: Give a pet, selection: each }\nsteps: [{ key: pet, type: create_record, object: pet, values: { name: 'Pet of {{ \$trigger.record.full_name }}' } }]");
        // All but two of the minute's runs are spent.
        $limit = self::getContainer()->getParameter('app.workflow.runs_per_minute');
        for ($i = 0; $i < $limit - 2; ++$i) {
            $run = new RunLog($workflow, 'person', null, 'manual', 'after', null, 1, new \DateTimeImmutable());
            $run->end(RunLog::COMPLETED);
            self::getContainer()->get(RunStore::class)->save($run);
        }

        // Three runs would stop after two, and leave the workspace none for its writes.
        self::assertSame(
            '3 records selected, a run each: the workspace may start 2 more this minute. Select fewer, or run it again in a minute.',
            $this->refusal(fn () => $this->runs()->runOnSelection($workflow, array_values($this->people), []))->getMessage(),
        );
        self::assertSame([], $this->pets());

        $runs = $this->runs()->runOnSelection($workflow, [$this->people['Ada'], $this->people['Bob']], []);
        self::assertSame([RunLog::COMPLETED, RunLog::COMPLETED], array_map(static fn (RunLog $run): string => $run->status, $runs));
    }

    public function testARunThatWaitsIsNoFailureForTheCommand(): void
    {
        $this->workflow("trigger: { on: manual, object: person, label: Later, selection: each }\nsteps: [{ key: later, type: wait, for: 2 days }, { key: pet, type: create_record, object: pet, values: { name: x } }]", 'Later');
        $command = new CommandTester((new Application(self::bootKernel()))->find('workflow:run'));

        self::assertSame(0, $command->execute(['workflow' => 'Later', '--record' => [$this->people['Ada'], $this->people['Bob']]]), $command->getDisplay());
        self::assertStringContainsString('waits until', $command->getDisplay());
        self::assertStringNotContainsString('failed', $command->getDisplay());
    }

    public function testRefusesWhatItMayNotRun(): void
    {
        $workflow = $this->workflow("trigger: { on: manual, object: person, label: Look, selection: each }\nsteps: [{ key: a, type: filter, when: { field: \$trigger.record.city, op: empty } }]");
        self::assertSame('No record selected.', $this->refusal(fn () => $this->runs()->runOnSelection($workflow, [], []))->getMessage());
        // Not left out in silence: the others would run, the one mistyped never.
        self::assertSame('"nope" is no record id.', $this->refusal(fn () => $this->runs()->runOnSelection($workflow, [$this->people['Ada'], 'nope'], []))->getMessage());
        self::assertSame('1 of the records selected are no more: run it again on the others.', $this->refusal(fn () => $this->runs()->runOnSelection($workflow, [$this->people['Ada'], '01a1e051-e8fe-7538-9ce4-ae37148a9711'], []))->getMessage());

        // The clerk holds the run permission, not reading people.
        self::getContainer()->get(RbacManagerInterface::class)->assign('clerk@example.com', Permissions::workflowRun($workflow->workflowId));
        $this->signIn('clerk@example.com');
        self::assertSame(ManualRunRefused::FORBIDDEN, $this->refusal(fn () => $this->runs()->runOnSelection($workflow, [$this->people['Ada']], []))->getCode());
    }

    public function testACommandRunsItOnTheRecordsGiven(): void
    {
        $this->workflow(<<<'YAML'
            trigger: { on: manual, object: person, label: Move, selection: each }
            steps:
              - { key: moved, type: update_record, object: person, id: '{{ $trigger.record.id }}', values: { city: Milano }, act_as: ROLE_SUPER_ADMIN }
            YAML, 'Move');
        $command = new CommandTester((new Application(self::bootKernel()))->find('workflow:run'));

        self::assertSame(0, $command->execute(['workflow' => 'Move', '--record' => [$this->people['Ada'], $this->people['Bob']]]), $command->getDisplay());
        $finder = self::getContainer()->get(RecordFinderInterface::class);
        self::assertSame(['Milano', 'Milano', 'Pisa'], array_map(static fn (string $id): mixed => $finder->find('person', $id)?->get('city'), array_values($this->people)));
    }

    private function workflow(string $yaml, string $name = 'Selection'): ActiveWorkflow
    {
        $this->signIn('owner@example.com');
        $manager = self::getContainer()->get(WorkflowManager::class);
        $workflow = $manager->create($name, null, $yaml);
        $manager->activate($workflow);

        return $this->runs()->get($workflow->getId()->toRfc4122());
    }

    private function runs(): ManualRuns
    {
        return self::getContainer()->get(ManualRuns::class);
    }

    private function signIn(string $email): void
    {
        $user = self::getContainer()->get(UserProvider::class)->loadUserByIdentifier($email);
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    /**
     * @param callable(): mixed $run
     */
    private function refusal(callable $run): ManualRunRefused
    {
        try {
            $run();
        } catch (ManualRunRefused $e) {
            return $e;
        }
        self::fail('Refused.');
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
