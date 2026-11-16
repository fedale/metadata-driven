<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Workflow;

use App\Module\Access\Application\PermissionSynchronizer;
use App\Module\Access\Contract\Permissions;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\ManualRuns;
use App\Module\Workflow\Application\PathCatalog;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition;
use App\Module\Workflow\Domain\Model\Workflow;
use App\Tests\Support\CreatesRelatedObjects;
use Doctrine\DBAL\Connection;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Fedale\RbacBundle\Enum\AuthItemType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Workflows on called, run by the run_workflow steps of others inside their runs: their input,
 * what they return, their own runs logged; who may call them; their failures and refusals.
 *
 * The owner activates them, holding every permission on person and pet. The clerk holds
 * ROLE_CLERK: reading people, creating pets.
 */
final class SubWorkflowsTest extends KernelTestCase
{
    use CreatesRelatedObjects;

    /** Names a pet: returns its name, refuses Rex. */
    private const string NAMING = <<<'YAML'
        trigger:
          on: called
          input: [{ name: owner, type: text, required: true }, { name: count, type: number }]
        steps:
          - key: check
            type: if
            branches:
              - when: { field: $trigger.input.owner, op: eq, value: Rex }
                steps: [{ key: no, type: refuse, message: 'No pet for {{ $trigger.input.owner }}.' }]
          - { key: back, type: return, values: { name: 'Pet {{ $trigger.input.count }} of {{ $trigger.input.owner }}' } }
          - { key: never, type: refuse, message: 'Not run: the return ended it.' }
        YAML;

    private const string GIVE_PET = <<<'YAML'
        trigger: { on: manual, object: person, label: Give a pet }
        steps:
          - { key: naming, type: run_workflow, workflow: Naming, input: { owner: '{{ $trigger.record.full_name }}', count: 2 } }
          - { key: pet, type: create_record, object: pet, values: { name: '{{ naming.name }}', owner: '{{ $trigger.record.id }}' } }
        YAML;

    private WorkflowManager $manager;

    private RecordManagerInterface $records;

    protected function setUp(): void
    {
        $container = self::getContainer();
        self::createRelatedObjects($container);
        $container->get(PermissionSynchronizer::class)->syncAll();
        $rbac = $container->get(RbacManagerInterface::class);
        $rbac->addItem('ROLE_CLERK', AuthItemType::ROLE);
        $rbac->addChild('ROLE_CLERK', 'record.person.read');
        $rbac->addChild('ROLE_CLERK', 'record.pet.create');
        foreach (['owner@example.com', 'clerk@example.com'] as $email) {
            $container->get(UserManagerInterface::class)->createUser($email, 'correct horse battery staple', 'Ada', 'Lovelace');
        }
        $rbac->assign('clerk@example.com', 'ROLE_CLERK');
        foreach (['person', 'pet'] as $object) {
            foreach (['read', 'create', 'update', 'delete'] as $action) {
                $rbac->assign('owner@example.com', 'record.'.$object.'.'.$action);
            }
        }
        $rbac->assign('owner@example.com', Permissions::WORKFLOWS);

        $this->manager = $container->get(WorkflowManager::class);
        $this->records = $container->get(RecordManagerInterface::class);
        $this->signIn('owner@example.com');
    }

    public function testRunsInsideItsCallerWithItsInputAndGivesBackWhatItReturns(): void
    {
        $naming = $this->workflow('Naming', self::NAMING);
        $this->give('owner@example.com', $naming);
        $caller = $this->workflow('Give', self::GIVE_PET);
        $this->give('clerk@example.com', $caller);
        $ada = $this->records->create(new RecordInput('person', ['full_name' => 'Ada']));

        $this->signIn('clerk@example.com');
        $runs = self::getContainer()->get(ManualRuns::class);
        $run = $runs->run($runs->get($caller->getId()->toRfc4122()), $ada->id, []);

        self::assertSame(RunLog::COMPLETED, $run->status, (string) $run->error);
        self::assertSame(['Pet 2 of Ada'], $this->pets());
        // Its own run, one level deeper, as whoever triggered its caller.
        $stored = self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT workflow_id, action, timing, triggered_by, depth, status FROM core.workflow_run ORDER BY depth');
        self::assertSame([
            [$caller->getId()->toRfc4122(), 'manual', 'manual', 'clerk@example.com', 1, 'completed'],
            [$naming->getId()->toRfc4122(), 'called', 'called', 'clerk@example.com', 2, 'completed'],
        ], array_map(array_values(...), $stored));
        self::assertSame(['name' => 'Pet 2 of Ada'], $run->steps[0]['output']);
        self::assertSame(['workflow' => 'Naming', 'input' => ['owner' => 'Ada', 'count' => 2], 'run' => self::getContainer()->get(Connection::class)->fetchOne('SELECT id FROM core.workflow_run WHERE depth = 2')], $run->steps[0]['input']);
        // The editor's picker: what its returns give.
        $paths = self::getContainer()->get(PathCatalog::class)->paths((new DefinitionParser())->parse(self::GIVE_PET));
        self::assertSame(['naming.name'], $paths['steps']['naming']);
    }

    public function testItsFailureFailsTheCallerAndNothingIsKept(): void
    {
        $this->give('owner@example.com', $this->workflow('Naming', self::NAMING));
        $caller = $this->workflow('Give', <<<'YAML'
            trigger: { on: manual, object: person, label: Give a pet }
            steps:
              - { key: first, type: create_record, object: pet, values: { name: First } }
              - { key: naming, type: run_workflow, workflow: Naming, input: { owner: '{{ $trigger.record.full_name }}' } }
            YAML);
        $this->give('owner@example.com', $caller);
        $rex = $this->records->create(new RecordInput('person', ['full_name' => 'Rex']));

        $runs = self::getContainer()->get(ManualRuns::class);
        $run = $runs->run($runs->get($caller->getId()->toRfc4122()), $rex->id, []);

        // After a write, its refuse fails it: all of the run or nothing.
        self::assertSame(RunLog::FAILED, $run->status);
        self::assertSame('"Naming" failed: No pet for Rex.', $run->error);
        self::assertSame([], $this->pets());
        self::assertSame(['failed', 'failed'], self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT status FROM core.workflow_run ORDER BY depth'));
    }

    public function testCalledBeforeAWriteItsRefuseRefusesTheWrite(): void
    {
        $this->give('owner@example.com', $this->workflow('Naming', self::NAMING));
        $this->workflow('Guard', <<<'YAML'
            trigger: { on: created, object: person, when: before }
            steps:
              - { key: naming, type: run_workflow, workflow: Naming, input: { owner: '{{ $trigger.record.full_name }}' } }
            YAML);

        $this->records->create(new RecordInput('person', ['full_name' => 'Ada']));
        try {
            $this->records->create(new RecordInput('person', ['full_name' => 'Rex']));
            self::fail('Refused.');
        } catch (RecordValidationFailed $e) {
            self::assertSame(['' => ['No pet for Rex.']], $e->errors);
        }
        self::assertSame(['completed', 'completed', 'refused', 'refused'], self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT status FROM core.workflow_run ORDER BY started_at, depth'));
    }

    public function testWhoActivatesTheCallerMustMayRunIt(): void
    {
        $naming = $this->workflow('Naming', self::NAMING);
        // Not given to the owner yet: activating a caller is refused.
        $refused = $this->problems(fn () => $this->workflow('Give', self::GIVE_PET));
        self::assertSame(['You may not run "Naming": a role of yours must give it, on the roles page.'], $refused['steps.0.workflow'] ?? null);

        $this->give('owner@example.com', $naming);
        $caller = $this->workflow('Give a pet', self::GIVE_PET);
        $this->give('owner@example.com', $caller);

        // Taken back since: the step fails at the run.
        self::getContainer()->get(RbacManagerInterface::class)->revoke('owner@example.com', Permissions::workflowRun($naming->getId()->toRfc4122()));
        $runs = self::getContainer()->get(ManualRuns::class);
        $run = $runs->run($runs->get($caller->getId()->toRfc4122()), $this->records->create(new RecordInput('person', ['full_name' => 'Ada']))->id, []);
        self::assertSame(RunLog::FAILED, $run->status);
        self::assertSame('owner@example.com, who activated it, may not run "Naming".', $run->error);
    }

    public function testWhatItNamesIsChecked(): void
    {
        $this->give('owner@example.com', $this->workflow('Naming', self::NAMING));
        $this->workflow('Manual', "trigger: { on: manual, object: person, label: Go }\nsteps: [{ key: a, type: filter, when: { field: \$trigger.record.city, op: empty } }]");

        $problems = $this->problems(fn () => $this->workflow('Give', <<<'YAML'
            trigger: { on: manual, object: person, label: Give a pet }
            steps:
              - { key: a, type: run_workflow, workflow: Nobody }
              - { key: b, type: run_workflow, workflow: Manual }
              - { key: c, type: run_workflow, workflow: Naming, input: { count: '{{ $trigger.record.nope }}', colour: red } }
            YAML));

        self::assertSame([
            'steps.0.workflow' => ['There is no active workflow "Nobody" on called.'],
            'steps.1.workflow' => ['There is no active workflow "Manual" on called.'],
            'steps.2.input.count' => ['Object "person" has no field "nope".'],
            'steps.2.input.colour' => ['"Naming" takes no input "colour".'],
            'steps.2.input' => ['"Naming" requires the input "owner".'],
        ], $problems);
        $problems = $this->problems(fn () => $this->workflow('Inputs', "trigger: { on: called, input: [{ name: a, type: text }] }\nsteps: [{ key: r, type: return, values: { b: '{{ \$trigger.input.b }}' } }]"));
        self::assertSame(['steps.0.values.b' => ['It takes no input "b".']], $problems);
    }

    public function testAnInputIsCheckedAtTheRun(): void
    {
        $this->give('owner@example.com', $this->workflow('Naming', self::NAMING));
        $caller = $this->workflow('Give', "trigger: { on: manual, object: person, label: Go }\nsteps: [{ key: n, type: run_workflow, workflow: Naming, input: { owner: x, count: '{{ \$trigger.record.full_name }}' } }]");
        $this->give('owner@example.com', $caller);
        $runs = self::getContainer()->get(ManualRuns::class);

        $run = $runs->run($runs->get($caller->getId()->toRfc4122()), $this->records->create(new RecordInput('person', ['full_name' => 'Ada']))->id, []);

        self::assertSame(RunLog::FAILED, $run->status);
        self::assertSame('The input "count" of "Naming": A number.', $run->error);
    }

    public function testACallerCallingItselfStopsAtTheDepthAllowed(): void
    {
        // Active first, calling itself once it is.
        $loop = $this->workflow('Loop', "trigger: { on: called }\nsteps: [{ key: r, type: return }]");
        $this->give('owner@example.com', $loop);
        $this->manager->saveDraft($loop, "trigger: { on: called }\nsteps: [{ key: again, type: run_workflow, workflow: Loop }]");
        $this->manager->activate($loop);
        $caller = $this->workflow('Start', "trigger: { on: manual, object: person, label: Go }\nsteps: [{ key: go, type: run_workflow, workflow: Loop }]");
        $this->give('owner@example.com', $caller);
        $runs = self::getContainer()->get(ManualRuns::class);

        $run = $runs->run($runs->get($caller->getId()->toRfc4122()), $this->records->create(new RecordInput('person', ['full_name' => 'Ada']))->id, []);

        self::assertSame(RunLog::FAILED, $run->status);
        $maxDepth = self::getContainer()->getParameter('app.workflow.max_depth');
        self::assertStringContainsString(\sprintf('at most %d', $maxDepth), (string) $run->error);
        self::assertSame($maxDepth, self::getContainer()->get(Connection::class)->fetchOne('SELECT max(depth) FROM core.workflow_run'));
    }

    public function testAReturnInAnIteratorEndsTheRun(): void
    {
        $first = $this->workflow('First', <<<'YAML'
            trigger: { on: called, input: [{ name: city, type: text }] }
            steps:
              - { key: people, type: search_records, object: person, sort: full_name }
              - key: each
                type: iterator
                items: '{{ people.records }}'
                steps:
                  - { key: found, type: filter, when: { field: each.item.city, op: eq, value: '{{ $trigger.input.city }}' } }
                  - { key: back, type: return, values: { name: '{{ each.item.full_name }}' } }
              - { key: none, type: return, values: { name: nobody } }
            YAML);
        $this->give('owner@example.com', $first);
        $caller = $this->workflow('Find', "trigger: { on: manual, object: person, label: Go }\nsteps: [{ key: f, type: run_workflow, workflow: First, input: { city: Torino } }, { key: p, type: create_record, object: pet, values: { name: '{{ f.name }}' } }]");
        $this->give('owner@example.com', $caller);
        $ada = $this->records->create(new RecordInput('person', ['full_name' => 'Ada', 'city' => 'Roma']));
        $this->records->create(new RecordInput('person', ['full_name' => 'Bea', 'city' => 'Torino']));
        $this->records->create(new RecordInput('person', ['full_name' => 'Cleo', 'city' => 'Torino']));
        $runs = self::getContainer()->get(ManualRuns::class);

        $run = $runs->run($runs->get($caller->getId()->toRfc4122()), $ada->id, []);

        self::assertSame(RunLog::COMPLETED, $run->status, (string) $run->error);
        self::assertSame(['Bea'], $this->pets());
    }

    public function testReturnAndWaitHaveTheirPlace(): void
    {
        $problems = static function (string $yaml): array {
            try {
                (new DefinitionParser())->parse($yaml);
            } catch (InvalidWorkflowDefinition $e) {
                return $e->errors;
            }

            return [];
        };

        self::assertSame(['steps.0.type' => ['return gives a called workflow\'s values to its caller: only in a workflow on called.']], $problems("trigger: { on: manual, object: person, label: Go }\nsteps: [{ key: r, type: return }]"));
        self::assertSame(['steps.0.type' => ['A called workflow runs inside its caller\'s run, which waits for no one: no wait.']], $problems("trigger: { on: called }\nsteps: [{ key: w, type: wait, for: 1 day }]"));
        self::assertSame(['trigger.input.0.type' => ['One of text, number, date, boolean, select.']], $problems("trigger: { on: called, input: [{ name: a, type: list }] }\nsteps: [{ key: r, type: return }]"));
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

    private function give(string $email, Workflow $workflow): void
    {
        self::getContainer()->get(RbacManagerInterface::class)->assign($email, Permissions::workflowRun($workflow->getId()->toRfc4122()));
    }

    /**
     * @return array<string, list<string>>
     */
    private function problems(callable $activation): array
    {
        try {
            $activation();
        } catch (InvalidWorkflowDefinition $e) {
            return $e->errors;
        }

        return [];
    }

    /**
     * @return list<mixed>
     */
    private function pets(): array
    {
        return array_map(static fn (RecordOutput $record): mixed => $record->get('name'), iterator_to_array(self::getContainer()->get(RecordFinderInterface::class)->each('pet'), false));
    }
}
