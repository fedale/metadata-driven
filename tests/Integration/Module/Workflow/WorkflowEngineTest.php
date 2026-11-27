<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Workflow;

use App\Module\Access\Application\PermissionSynchronizer;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Domain\Model\Workflow;
use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\Workspaces;
use App\Tests\Support\CreatesRelatedObjects;
use App\Tests\Support\CreatesWorkspaces;
use App\Tests\Support\RunErrors;
use Doctrine\DBAL\Connection;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Fedale\RbacBundle\Enum\AuthItemType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Record workflows run end to end: before and after a write, with their permissions, their
 * limits and their runs. The ways to abuse them first.
 *
 * The clerk holds ROLE_CLERK: every permission on person, creating and updating pets. The owner
 * holds ROLE_OWNER, which contains ROLE_CLERK and every permission on invoice.
 */
final class WorkflowEngineTest extends KernelTestCase
{
    use CreatesRelatedObjects;
    use CreatesWorkspaces;

    private RecordManagerInterface $records;

    private WorkflowManager $manager;

    protected function setUp(): void
    {
        $container = self::getContainer();
        self::createRelatedObjects($container);
        $container->get(MetadataManagerInterface::class)->createField('invoice', 'code', 'text', 'Code', unique: true);
        $container->get(PermissionSynchronizer::class)->syncAll();
        $rbac = $container->get(RbacManagerInterface::class);
        $rbac->addItem('ROLE_CLERK', AuthItemType::ROLE);
        $rbac->addItem('ROLE_OWNER', AuthItemType::ROLE);
        foreach (['read', 'create', 'update', 'delete'] as $action) {
            $rbac->addChild('ROLE_CLERK', 'record.person.'.$action);
            $rbac->addChild('ROLE_OWNER', 'record.invoice.'.$action);
        }
        $rbac->addChild('ROLE_CLERK', 'record.pet.create');
        $rbac->addChild('ROLE_CLERK', 'record.pet.update');
        $rbac->addChild('ROLE_OWNER', 'ROLE_CLERK');
        foreach (['clerk@example.com' => 'ROLE_CLERK', 'owner@example.com' => 'ROLE_OWNER'] as $email => $role) {
            $container->get(UserManagerInterface::class)->createUser($email, 'correct horse battery staple', 'Ada', 'Lovelace');
            $rbac->assign($email, $role);
        }
        $this->records = $container->get(RecordManagerInterface::class);
        $this->manager = $container->get(WorkflowManager::class);
    }

    public function testAnAfterWorkflowWritesAsWhoeverTriggeredItAndKeepsItsRun(): void
    {
        $this->signIn('clerk@example.com');
        $this->workflow('Welcome pet', <<<'YAML'
            trigger: { on: created, object: person, when: after }
            steps:
              - { key: pet, type: create_record, object: pet, values: { name: 'Pet of {{ $trigger.record.full_name }}', owner: '{{ $trigger.record.id }}' } }
            YAML);

        $ada = $this->records->create(new RecordInput('person', ['full_name' => 'Ada']));

        self::assertSame(['Pet of Ada'], $this->names('pet'));
        $run = $this->runs()[0];
        self::assertSame(['completed', 'person', $ada->id, 'create', 'after', 'clerk@example.com', 1], [$run['status'], $run['object'], $run['record_id'], $run['action'], $run['timing'], $run['triggered_by'], $run['depth']]);
        self::assertSame([['pet', 'create_record', 'completed']], $this->steps($run['id']));
        self::assertEquals(1, self::getContainer()->get(Connection::class)->fetchOne('SELECT steps FROM core.workflow_usage'));
    }

    public function testABeforeWorkflowChangesTheValuesOrRefusesTheWriteAndItsRunIsKept(): void
    {
        $this->signIn('clerk@example.com');
        $this->workflow('Guard', <<<'YAML'
            trigger: { on: created_or_updated, object: person, when: before }
            steps:
              - key: check
                type: if
                branches:
                  - when: { field: $trigger.record.full_name, op: eq, value: Mallory }
                    steps: [{ key: no, type: refuse, message: 'No {{ $trigger.record.full_name }} here.' }]
                  - when: { field: $trigger.record.city, op: empty }
                    steps: [{ key: city, type: set_values, values: { city: Torino } }]
            YAML);

        self::assertSame('Torino', $this->records->create(new RecordInput('person', ['full_name' => 'Ada']))->get('city'));
        try {
            $this->records->create(new RecordInput('person', ['full_name' => 'Mallory']));
            self::fail('Refused.');
        } catch (RecordValidationFailed $e) {
            self::assertSame(['' => ['No Mallory here.']], array_map(static fn (array $messages): array => array_map(strval(...), $messages), $e->errors));
        }

        self::assertSame(['Ada'], $this->names('person'));
        // The refused write was rolled back; its run was kept.
        self::assertSame(['completed', 'refused'], array_column($this->runs(), 'status'));
        self::assertSame([['check', 'if', 'completed'], ['no', 'refuse', 'completed']], $this->steps($this->runs()[1]['id']));
    }

    public function testAStepGivesNoMorePowerThanWhoeverTriggeredTheEventHas(): void
    {
        $this->signIn('owner@example.com');
        $this->workflow('Bill', <<<'YAML'
            trigger: { on: updated, object: person, when: after }
            steps:
              - { key: bill, type: create_record, object: invoice, values: { number: X1, customer: '{{ $trigger.record.id }}' } }
            YAML);
        $this->signIn('clerk@example.com');
        $customer = $this->records->create(new RecordInput('person', ['full_name' => 'Ada']));

        $this->records->update($customer->id, new RecordInput('person', ['city' => 'Milano']));

        // The write stays; the run failed: the clerk may not create invoices.
        self::assertSame('Milano', $this->find('person', $customer->id)?->get('city'));
        self::assertSame([], $this->names('invoice'));
        self::assertSame('failed', $this->runs()[0]['status']);
        self::assertSame('clerk@example.com does not hold record.invoice.create.', RunErrors::english($this->runs()[0]['error']));
    }

    public function testItsActivatorMayNotWriteThroughWhoeverTriggersIt(): void
    {
        // The clerk may not create invoices: a workflow of theirs may not either, whoever
        // triggers it.
        $this->signIn('clerk@example.com');
        try {
            $this->workflow('Bill', <<<'YAML'
                trigger: { on: updated, object: person, when: after }
                steps: [{ key: bill, type: create_record, object: invoice, values: { number: X1 } }]
                YAML);
            self::fail('Refused.');
        } catch (\App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition $e) {
            self::assertStringContainsString('You do not hold record.invoice.create', $e->getMessage());
        }

        // Activated by the owner, then the owner demoted: the step stops, though whoever triggers
        // it holds the permission.
        $this->signIn('owner@example.com');
        $this->workflow('Bill as the owner', <<<'YAML'
            trigger: { on: updated, object: person, when: after }
            steps: [{ key: bill, type: create_record, object: invoice, values: { number: X1 } }]
            YAML);
        $rbac = self::getContainer()->get(RbacManagerInterface::class);
        $rbac->assign('clerk@example.com', 'ROLE_OWNER');
        $rbac->revoke('owner@example.com', 'ROLE_OWNER');
        $this->signIn('clerk@example.com');
        $customer = $this->records->create(new RecordInput('person', ['full_name' => 'Ada']));
        $this->records->update($customer->id, new RecordInput('person', ['city' => 'Milano']));

        self::assertSame([], $this->names('invoice', 'number'));
        self::assertSame('owner@example.com, who activated it, no longer holds record.invoice.create.', RunErrors::english($this->runs()[0]['error']));
    }

    public function testSetValuesStopsOnceItsActivatorMayNoLongerWriteThere(): void
    {
        $this->signIn('owner@example.com');
        $this->workflow('Stamp', "trigger: { on: created, object: invoice, when: before }\nsteps: [{ key: stamp, type: set_values, values: { number: STAMPED } }]");
        $rbac = self::getContainer()->get(RbacManagerInterface::class);
        $rbac->assign('clerk@example.com', 'ROLE_OWNER');
        $this->signIn('clerk@example.com');
        self::assertSame('STAMPED', $this->records->create(new RecordInput('invoice', ['number' => 'A1']))->get('number'));

        // The owner demoted: the workflow guards the write, and what it can no longer do refuses it.
        $rbac->revoke('owner@example.com', 'ROLE_OWNER');
        try {
            $this->records->create(new RecordInput('invoice', ['number' => 'A2']));
            self::fail('Refused.');
        } catch (RecordValidationFailed $e) {
            self::assertSame(['' => ['The workflow "Stamp" could not run: owner@example.com, who activated it, no longer holds record.invoice.create.']], array_map(static fn (array $messages): array => array_map(strval(...), $messages), $e->errors));
        }
    }

    public function testARoleStepStopsOnceItsActivatorCanNoLongerGiveTheRole(): void
    {
        $this->signIn('owner@example.com');
        $this->workflow('Bill as owner', <<<'YAML'
            trigger: { on: updated, object: person, when: after }
            steps:
              - { key: bill, type: create_record, object: invoice, values: { number: X1 }, act_as: ROLE_OWNER }
            YAML);
        $this->signIn('clerk@example.com');
        $customer = $this->records->create(new RecordInput('person', ['full_name' => 'Ada']));

        // The clerk triggers it; the step acts as ROLE_OWNER, which its activator may give.
        $this->records->update($customer->id, new RecordInput('person', ['city' => 'Milano']));
        self::assertSame(['X1'], $this->names('invoice', 'number'));

        // The activator demoted: the step stops, and the run fails, visibly.
        self::getContainer()->get(RbacManagerInterface::class)->revoke('owner@example.com', 'ROLE_OWNER');
        $this->records->update($customer->id, new RecordInput('person', ['city' => 'Torino']));
        self::assertSame(['X1'], $this->names('invoice', 'number'));
        self::assertSame('It acts as ROLE_OWNER, which owner@example.com can no longer give.', RunErrors::english($this->runs()[1]['error']));
    }

    public function testAChainOfRunsStopsAtItsDepth(): void
    {
        $this->signIn('clerk@example.com');
        $this->workflow('Loop', <<<'YAML'
            trigger: { on: updated, object: person, when: after, fields: [city] }
            steps:
              - { key: again, type: update_record, object: person, id: '{{ $trigger.record.id }}', values: { city: '{{ $trigger.record.city }}x' }, emit_events: true }
            YAML);
        $ada = $this->records->create(new RecordInput('person', ['full_name' => 'Ada', 'city' => 'a']));

        $this->records->update($ada->id, new RecordInput('person', ['city' => 'b']));

        // Runs at depths 1, 2 and 3 write; the fourth is refused.
        self::assertSame('bxxx', $this->find('person', $ada->id)?->get('city'));
        self::assertSame([[1, 'completed'], [2, 'completed'], [3, 'completed'], [4, 'failed']], array_map(static fn (array $run): array => [$run['depth'], $run['status']], $this->runs()));
    }

    public function testAWorkflowsWritesStartNoOtherWorkflowUnlessItSaysSo(): void
    {
        $this->signIn('clerk@example.com');
        $this->workflow('Welcome pet', <<<'YAML'
            trigger: { on: created, object: person, when: after }
            steps: [{ key: pet, type: create_record, object: pet, values: { name: Rex } }]
            YAML);
        $this->workflow('Name pets', <<<'YAML'
            trigger: { on: created, object: pet, when: before }
            steps: [{ key: name, type: set_values, values: { name: Renamed } }]
            YAML);

        $this->records->create(new RecordInput('person', ['full_name' => 'Ada']));

        self::assertSame(['Rex'], $this->names('pet'));
    }

    public function testNoOneBehindTheEventMeansARoleMustBeChosen(): void
    {
        $this->signIn('clerk@example.com');
        $this->workflow('Welcome pet', <<<'YAML'
            trigger: { on: created, object: person, when: after }
            steps: [{ key: pet, type: create_record, object: pet, values: { name: Rex } }]
            YAML);
        self::getContainer()->get(TokenStorageInterface::class)->setToken(null);

        $this->records->create(new RecordInput('person', ['full_name' => 'Ada']));

        self::assertSame([], $this->names('pet'));
        self::assertSame('No one triggered this event: choose a role for the step to act as.', RunErrors::english($this->runs()[0]['error']));
        self::assertNull($this->runs()[0]['triggered_by']);
    }

    public function testAnAfterRunIsAllOrNothing(): void
    {
        $this->signIn('clerk@example.com');
        $this->workflow('Two pets', <<<'YAML'
            trigger: { on: created, object: person, when: after }
            steps:
              - { key: first, type: create_record, object: pet, values: { name: First } }
              - { key: second, type: create_record, object: pet, values: { name: '' } }
            YAML);

        $this->records->create(new RecordInput('person', ['full_name' => 'Ada']));

        self::assertSame([], $this->names('pet'));
        self::assertSame([['first', 'create_record', 'completed'], ['second', 'create_record', 'failed']], $this->steps($this->runs()[0]['id']));
    }

    public function testWorkflowsOfOneEventRunInTheListsOrder(): void
    {
        $this->signIn('clerk@example.com');
        $first = $this->workflow('First', "trigger: { on: created, object: person, when: before }\nsteps: [{ key: a, type: set_values, values: { city: First } }]");
        $second = $this->workflow('Second', "trigger: { on: created, object: person, when: before }\nsteps: [{ key: b, type: set_values, values: { city: Second } }]");

        self::assertSame('Second', $this->records->create(new RecordInput('person', ['full_name' => 'Ada']))->get('city'));
        $this->manager->reorder([$second, $first]);
        self::assertSame('First', $this->records->create(new RecordInput('person', ['full_name' => 'Bea']))->get('city'));
    }

    public function testAFilterEndsTheRunAndAnUpsertMatchesOnAUniqueField(): void
    {
        $this->signIn('owner@example.com');
        $this->workflow('Bill once', <<<'YAML'
            trigger: { on: updated, object: person, when: after }
            steps:
              - { key: billable, type: filter, when: { field: $trigger.record.city, op: not_empty } }
              - { key: bill, type: upsert_record, object: invoice, match: code, values: { code: 'B-{{ $trigger.record.full_name }}', number: '{{ $trigger.record.city }}' } }
            YAML);
        $ada = $this->records->create(new RecordInput('person', ['full_name' => 'Ada']));

        $this->records->update($ada->id, new RecordInput('person', ['manager' => null]));
        $this->records->update($ada->id, new RecordInput('person', ['city' => 'Roma']));
        $this->records->update($ada->id, new RecordInput('person', ['city' => 'Pisa']));

        // Created once, then updated: matched on its code.
        self::assertSame(['Pisa'], $this->names('invoice', 'number'));
        self::assertSame([[['billable', 'filter', 'completed']], [['billable', 'filter', 'completed'], ['bill', 'upsert_record', 'completed']], [['billable', 'filter', 'completed'], ['bill', 'upsert_record', 'completed']]], array_map(fn (array $run): array => $this->steps($run['id']), $this->runs()));
    }

    public function testAWorkspaceRunsItsOwnWorkflowsOnly(): void
    {
        $container = self::getContainer();
        $this->signIn('clerk@example.com');
        $this->workflow('Welcome pet', <<<'YAML'
            trigger: { on: created, object: person, when: after }
            steps: [{ key: pet, type: create_record, object: pet, values: { name: Rex } }]
            YAML);
        $home = $container->get(Workspaces::class)->byId($container->get(CurrentWorkspace::class)->id());
        self::assertNotNull($home);
        $other = self::createWorkspace($container, 'globex', 'Globex');
        self::useWorkspace($container, $other);
        self::createRelatedObjects($container);

        $this->records->create(new RecordInput('person', ['full_name' => 'Ada']));

        self::assertSame([], $this->names('pet'));
        self::assertSame([], $this->runs());
        self::useWorkspace($container, $home);
        self::assertSame([], $this->runs());
    }

    public function testADryRunRunsTheStepsAndKeepsNothing(): void
    {
        $this->signIn('clerk@example.com');
        $ada = $this->records->create(new RecordInput('person', ['full_name' => 'Ada', 'city' => 'Pisa']));
        // Another workflow, which a write of the test would start were its events raised.
        $this->workflow('Name pets', "trigger: { on: created, object: pet, when: before }\nsteps: [{ key: n, type: set_values, values: { name: Renamed } }]");
        $definition = (new \App\Module\Workflow\Application\Definition\DefinitionParser())->parse(<<<'YAML'
            trigger: { on: updated, object: person, when: after }
            steps:
              - { key: pet, type: create_record, object: pet, values: { name: 'Pet of {{ $trigger.record.full_name }}' }, emit_events: true }
              - { key: again, type: update_record, object: person, id: '{{ $trigger.record.id }}', values: { city: '{{ $trigger.record.city }}!' } }
            YAML);

        $run = self::getContainer()->get(\App\Module\Workflow\Application\Engine\WorkflowEngine::class)->dryRun($definition, 'Test', $ada->id);

        self::assertSame('completed', $run->status);
        [$pet, $again] = [$run->steps[0]['output'], $run->steps[1]['output']];
        self::assertIsArray($pet);
        self::assertIsArray($again);
        self::assertSame('Pet of Ada', $pet['name'] ?? null);
        self::assertSame('Pisa!', $again['city'] ?? null);
        self::assertSame([], $this->names('pet'));
        self::assertSame('Pisa', $this->find('person', $ada->id)?->get('city'));
        self::assertSame([], $this->runs());
    }

    public function testADryRunOfABeforeWorkflowShowsItsRefusal(): void
    {
        $this->signIn('clerk@example.com');
        $ada = $this->records->create(new RecordInput('person', ['full_name' => 'Ada']));
        $definition = (new \App\Module\Workflow\Application\Definition\DefinitionParser())->parse(<<<'YAML'
            trigger: { on: created_or_updated, object: person, when: before }
            steps:
              - { key: city, type: set_values, values: { city: Torino } }
              - { key: no, type: refuse, message: 'No {{ $trigger.record.full_name }}.' }
            YAML);

        $run = self::getContainer()->get(\App\Module\Workflow\Application\Engine\WorkflowEngine::class)->dryRun($definition, 'Test', $ada->id);

        self::assertSame(['refused', 'No Ada.'], [$run->status, RunErrors::english($run->error)]);
        self::assertSame(['city' => 'Torino'], $run->steps[0]['output']);
        self::assertSame([], $this->runs());
    }

    private function signIn(string $email): void
    {
        $user = self::getContainer()->get(UserProvider::class)->loadUserByIdentifier($email);
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function workflow(string $name, string $yaml): Workflow
    {
        $workflow = $this->manager->create($name, null, $yaml);
        $this->manager->activate($workflow);

        return $workflow;
    }

    private function find(string $object, string $id): ?RecordOutput
    {
        return self::getContainer()->get(RecordFinderInterface::class)->find($object, $id);
    }

    /**
     * @return list<mixed>
     *
     * @phpstan-impure the records change between two calls
     */
    private function names(string $object, string $field = 'name'): array
    {
        $object = 'person' === $object ? 'person' : $object;
        if ('person' === $object && 'name' === $field) {
            $field = 'full_name';
        }

        return array_map(static fn (RecordOutput $record): mixed => $record->get($field), iterator_to_array(self::getContainer()->get(RecordFinderInterface::class)->each($object), false));
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @phpstan-impure the runs change between two calls
     */
    private function runs(): array
    {
        return self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT * FROM core.workflow_run ORDER BY started_at, depth');
    }

    /**
     * @return list<array{mixed, mixed, mixed}>
     */
    private function steps(mixed $runId): array
    {
        return array_map(
            static fn (array $row): array => [$row['key'], $row['type'], $row['status']],
            self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT key, type, status FROM core.workflow_run_step WHERE run_id = ? ORDER BY position', [$runId]),
        );
    }
}
