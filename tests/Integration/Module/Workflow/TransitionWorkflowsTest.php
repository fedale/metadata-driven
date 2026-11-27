<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Workflow;

use App\Module\Access\Application\PermissionSynchronizer;
use App\Module\Access\Contract\Permissions;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Record\Contract\RecordTransitionsInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\ManualRuns;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition;
use App\Tests\Support\RunErrors;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Fedale\RbacBundle\Enum\AuthItemType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Transitions in workflows: a trigger (before it may refuse it, a transition's conditions; after
 * it reacts) and a step. The owner, a super admin, activates them; the clerk holds ROLE_CLERK:
 * invoices and notes, not the boss's transitions.
 */
final class TransitionWorkflowsTest extends KernelTestCase
{
    private RecordOutput $invoice;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $metadata = $container->get(MetadataManagerInterface::class);
        $metadata->createObject('invoice', 'Invoice', 'Invoices');
        $metadata->createField('invoice', 'number', 'text', 'Number');
        $metadata->createField('invoice', 'state', 'status', 'State', settings: [
            'states' => [['value' => 'draft', 'label' => 'Draft'], ['value' => 'sent', 'label' => 'Sent'], ['value' => 'paid', 'label' => 'Paid']],
            'transitions' => [
                ['name' => 'send', 'label' => 'Send', 'from' => ['draft'], 'to' => 'sent', 'roles' => []],
                ['name' => 'pay', 'label' => 'Pay', 'from' => ['sent'], 'to' => 'paid', 'roles' => []],
                ['name' => 'reopen', 'label' => 'Reopen', 'from' => ['sent', 'paid'], 'to' => 'draft', 'roles' => ['ROLE_BOSS']],
            ],
        ]);
        $metadata->createObject('note', 'Note', 'Notes');
        $metadata->createField('note', 'text', 'text', 'Text');
        $container->get(PermissionSynchronizer::class)->syncAll();
        $rbac = $container->get(RbacManagerInterface::class);
        $rbac->addItem('ROLE_CLERK', AuthItemType::ROLE);
        $rbac->addItem('ROLE_BOSS', AuthItemType::ROLE);
        foreach (['invoice.read', 'invoice.create', 'invoice.update', 'note.create'] as $permission) {
            $rbac->addChild('ROLE_CLERK', 'record.'.$permission);
            $rbac->addChild('ROLE_BOSS', 'record.'.$permission);
        }
        foreach (['owner' => Permissions::SUPER_ADMIN_ROLE, 'clerk' => 'ROLE_CLERK'] as $who => $role) {
            $container->get(UserManagerInterface::class)->createUser($who.'@example.com', 'correct horse battery staple');
            $rbac->assign($who.'@example.com', $role);
        }
        $this->signIn('owner@example.com');
        $this->invoice = $container->get(RecordManagerInterface::class)->create(new RecordInput('invoice', []));
    }

    public function testABeforeWorkflowIsTheTransitionsCondition(): void
    {
        $this->activate('Numbered', <<<'YAML'
            trigger: { on: transitioned, object: invoice, field: state, transition: send, when: before }
            steps:
              - key: unnumbered
                type: if
                branches: [{ when: { field: $trigger.record.number, op: empty }, steps: [{ key: no, type: refuse, message: 'Number it before {{ $trigger.transition.name }}.' }] }]
            YAML);
        $this->signIn('clerk@example.com');
        $transitions = self::getContainer()->get(RecordTransitionsInterface::class);

        try {
            $transitions->apply('invoice', $this->invoice->id, 'state', 'send');
            self::fail('Refused.');
        } catch (RecordValidationFailed $e) {
            self::assertSame([RecordValidationFailed::GLOBAL_ERRORS => ['Number it before send.']], array_map(static fn (array $messages): array => array_map(strval(...), $messages), $e->errors));
        }
        self::getContainer()->get(RecordManagerInterface::class)->update($this->invoice->id, new RecordInput('invoice', ['number' => 'A1']));
        self::assertSame('sent', $transitions->apply('invoice', $this->invoice->id, 'state', 'send')->get('state'));
    }

    public function testAnAfterWorkflowReactsToAnyTransitionOfTheField(): void
    {
        $this->activate('Log', <<<'YAML'
            trigger: { on: transitioned, object: invoice, field: state, when: after }
            steps:
              - { key: note, type: create_record, object: note, values: { text: '{{ $trigger.transition.name }}: {{ $trigger.transition.from }} to {{ $trigger.transition.to }}' } }
            YAML);
        $this->signIn('clerk@example.com');
        $transitions = self::getContainer()->get(RecordTransitionsInterface::class);
        $transitions->apply('invoice', $this->invoice->id, 'state', 'send');
        $transitions->apply('invoice', $this->invoice->id, 'state', 'pay');
        // An update that takes no transition starts it not.
        self::getContainer()->get(RecordManagerInterface::class)->update($this->invoice->id, new RecordInput('invoice', ['number' => 'A1']));

        self::assertSame(['send: draft to sent', 'pay: sent to paid'], array_map(static fn (RecordOutput $note): mixed => $note->get('text'), iterator_to_array(self::getContainer()->get(RecordFinderInterface::class)->each('note'), false)));
    }

    public function testAStepTakesATransitionWithItsRoles(): void
    {
        $yaml = <<<'YAML'
            trigger: { on: manual, object: invoice, label: Back to draft }
            steps:
              - { key: sent, type: transition, object: invoice, id: '{{ $trigger.record.id }}', field: state, name: send }
              - { key: back, type: transition, object: invoice, id: '{{ $trigger.record.id }}', field: state, name: reopen }
            YAML;
        $this->activate('As clerk', $yaml);
        $this->activate('As boss', str_replace('name: reopen }', 'name: reopen, act_as: ROLE_BOSS }', $yaml));
        $runs = self::getContainer()->get(ManualRuns::class);

        // As the clerk: send is anyone's, reopen the boss's; all or nothing.
        $this->signIn('clerk@example.com');
        $run = $runs->run($runs->get($this->workflowId('As clerk')), $this->invoice->id, [], asSomeone: false);
        self::assertSame([RunLog::FAILED, '"Reopen" is taken by ROLE_BOSS only.'], [$run->status, RunErrors::english($run->error)]);
        self::assertSame('draft', $this->state());

        // As the boss's role: both taken.
        $run = $runs->run($runs->get($this->workflowId('As boss')), $this->invoice->id, [], asSomeone: false);
        self::assertSame(RunLog::COMPLETED, $run->status, (string) $run->error);
        self::assertSame([['state' => 'sent'], ['state' => 'draft']], array_map(static fn (array $step): array => ['state' => \is_array($step['output']) ? $step['output']['state'] : null], $run->steps));
    }

    public function testItsActivatorMayNotTakeThroughTheUsersWhatTheyCannot(): void
    {
        // The clerk may define workflows, and holds none of reopen's roles.
        self::getContainer()->get(RbacManagerInterface::class)->assign('clerk@example.com', Permissions::WORKFLOWS);
        $this->signIn('clerk@example.com');
        $manager = self::getContainer()->get(WorkflowManager::class);
        $yaml = "trigger: { on: manual, object: invoice, label: Back }\nsteps: [{ key: back, type: transition, object: invoice, id: '{{ \$trigger.record.id }}', field: state, name: reopen }]";
        try {
            $manager->activate($manager->create('Sneaky', null, $yaml));
            self::fail('Refused.');
        } catch (InvalidWorkflowDefinition $e) {
            self::assertSame(['You hold none of ROLE_BOSS: a step taking "Reopen" as whoever triggered the event may not do what you cannot. Give it an act_as.'], array_map(strval(...), $e->errors['steps.0.name']));
        }
        try {
            $manager->activate($manager->create('Sneakier', null, str_replace('name: reopen }', 'name: reopen, act_as: ROLE_CLERK }', $yaml)));
            self::fail('Refused.');
        } catch (InvalidWorkflowDefinition $e) {
            self::assertSame(['"Reopen" is taken by ROLE_BOSS only: ROLE_CLERK is none of them.'], array_map(strval(...), $e->errors['steps.0.act_as']));
        }
    }

    public function testItsStepStopsOnceItsActivatorHoldsNoneOfItsRoles(): void
    {
        $rbac = self::getContainer()->get(RbacManagerInterface::class);
        self::getContainer()->get(UserManagerInterface::class)->createUser('boss@example.com', 'correct horse battery staple');
        $rbac->assign('boss@example.com', 'ROLE_BOSS');
        $rbac->assign('boss@example.com', Permissions::WORKFLOWS);
        // Updating invoices on its own: losing the role, the boss keeps that.
        $rbac->assign('boss@example.com', 'record.invoice.update');
        $this->signIn('boss@example.com');
        $manager = self::getContainer()->get(WorkflowManager::class);
        $manager->activate($manager->create('Back', null, <<<'YAML'
            trigger: { on: manual, object: invoice, label: Back }
            steps:
              - { key: sent, type: transition, object: invoice, id: '{{ $trigger.record.id }}', field: state, name: send }
              - { key: back, type: transition, object: invoice, id: '{{ $trigger.record.id }}', field: state, name: reopen }
            YAML));
        // Demoted: the owner, who holds every role, runs it; its step stops.
        $rbac->revoke('boss@example.com', 'ROLE_BOSS');
        $this->signIn('owner@example.com');
        $runs = self::getContainer()->get(ManualRuns::class);

        $run = $runs->run($runs->get($this->workflowId('Back')), $this->invoice->id, [], asSomeone: false);

        self::assertSame([RunLog::FAILED, 'boss@example.com, who activated it, holds none of ROLE_BOSS.'], [$run->status, RunErrors::english($run->error)]);
        self::assertSame('draft', $this->state());
    }

    public function testATransitionNamedIsOneOfTheField(): void
    {
        $manager = self::getContainer()->get(WorkflowManager::class);
        $workflow = $manager->create('Wrong', null, "trigger: { on: transitioned, object: invoice, field: number, transition: send, when: after }\nsteps: [{ key: t, type: transition, object: invoice, id: '{{ \$trigger.record.id }}', field: state, name: burn }]");
        try {
            $manager->activate($workflow);
            self::fail('Refused.');
        } catch (InvalidWorkflowDefinition $e) {
            self::assertSame(['Object "invoice" has no status field "number".'], array_map(strval(...), $e->errors['trigger.field']));
            self::assertSame(['The status "state" has no transition "burn".'], array_map(strval(...), $e->errors['steps.0.name']));
        }
    }

    private function activate(string $name, string $yaml): void
    {
        $this->signIn('owner@example.com');
        $manager = self::getContainer()->get(WorkflowManager::class);
        $manager->activate($manager->create($name, null, $yaml));
    }

    private function workflowId(string $name): string
    {
        $workflow = self::getContainer()->get(\App\Module\Workflow\Domain\Repository\WorkflowRepository::class)->findByName($name);
        self::assertNotNull($workflow);

        return $workflow->getId()->toRfc4122();
    }

    private function state(): mixed
    {
        return self::getContainer()->get(RecordFinderInterface::class)->find('invoice', $this->invoice->id)?->get('state');
    }

    private function signIn(string $email): void
    {
        $user = self::getContainer()->get(UserProvider::class)->loadUserByIdentifier($email);
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
