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
use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\ManualRunRefused;
use App\Module\Workflow\Application\ManualRuns;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Domain\Model\Workflow;
use App\Tests\Support\CreatesRelatedObjects;
use Doctrine\DBAL\Connection;
use Fedale\RbacBundle\Contract\ItemStorageInterface;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Fedale\RbacBundle\Enum\AuthItemType;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Manual workflows: their permission, their runs with the answers of their form, as whoever runs
 * them or, from a command, as no one. The ways to abuse them first.
 *
 * The owner activates them, holding every permission on person and creating pets. The clerk holds
 * ROLE_CLERK: reading people, creating pets.
 */
final class ManualRunsTest extends KernelTestCase
{
    use CreatesRelatedObjects;

    private const string RENAME = <<<'YAML'
        trigger:
          on: manual
          object: person
          label: Give a pet
          form: [{ name: name, label: Name, type: text, required: true }, { name: count, label: Count, type: number }]
        steps:
          - { key: pet, type: create_record, object: pet, values: { name: '{{ $trigger.input.name }} of {{ $trigger.record.full_name }}', owner: '{{ $trigger.record.id }}' } }
        YAML;

    private ManualRuns $runs;

    private WorkflowManager $manager;

    private RecordOutput $ada;

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
        foreach (['read', 'create', 'update', 'delete'] as $action) {
            $rbac->assign('owner@example.com', 'record.person.'.$action);
        }
        $rbac->assign('owner@example.com', 'record.pet.create');
        $rbac->assign('owner@example.com', Permissions::WORKFLOWS);

        $this->runs = $container->get(ManualRuns::class);
        $this->manager = $container->get(WorkflowManager::class);
        $this->signIn('owner@example.com');
        $this->ada = $container->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada']));
    }

    public function testItsPermissionComesWithItsActivationAndGoesWithIt(): void
    {
        $workflow = $this->workflow('Pet', self::RENAME);
        $permission = Permissions::workflowRun($workflow->getId()->toRfc4122());
        $items = self::getContainer()->get(ItemStorageInterface::class);
        self::assertSame('Pet', $items->getItem($permission)?->description);

        // Held by no one yet: not even the one who activated it may run it.
        self::assertSame([], $this->runs->runnableOn('person'));
        $this->give('owner@example.com', $workflow);
        self::assertSame(['Pet'], array_map(static fn ($active): string => $active->name, $this->runs->runnableOn('person')));

        // No longer manual: no longer a permission.
        $this->manager->saveDraft($workflow, "trigger: { on: created, object: person, when: after }\nsteps: [{ key: a, type: filter, when: { field: \$trigger.record.city, op: empty } }]");
        $this->manager->activate($workflow);
        self::assertNull($items->getItem($permission));

        $other = $this->workflow('Other', self::RENAME);
        $this->manager->delete($other);
        self::assertNull($items->getItem(Permissions::workflowRun($other->getId()->toRfc4122())));
    }

    public function testRunsWithTheAnswersAsWhoeverRunsIt(): void
    {
        $workflow = $this->workflow('Pet', self::RENAME);
        $this->give('clerk@example.com', $workflow);
        $this->signIn('clerk@example.com');

        $run = $this->runs->run($this->runs->get($workflow->getId()->toRfc4122()), $this->ada->id, ['name' => 'Cat', 'count' => '2']);

        self::assertSame(RunLog::COMPLETED, $run->status, (string) $run->error);
        self::assertSame(['Cat of Ada'], $this->pets());
        $stored = self::getContainer()->get(Connection::class)->fetchAssociative('SELECT * FROM core.workflow_run');
        self::assertIsArray($stored);
        self::assertSame(['manual', 'manual', 'clerk@example.com', $this->ada->id], [$stored['action'], $stored['timing'], $stored['triggered_by'], $stored['record_id']]);
        self::assertIsString($stored['chain']);
        self::assertStringContainsString('"input": {"name": "Cat", "count": 2}', $stored['chain']);
    }

    public function testWhoMayNotRunItCannotAndBadAnswersStartNothing(): void
    {
        $workflow = $this->workflow('Pet', self::RENAME);
        $active = $this->runs->get($workflow->getId()->toRfc4122());
        $this->signIn('clerk@example.com');
        self::assertSame(ManualRunRefused::FORBIDDEN, $this->refusal(fn () => $this->runs->run($active, $this->ada->id, ['name' => 'Cat']))->getCode());

        $this->give('clerk@example.com', $workflow);
        $refused = $this->refusal(fn () => $this->runs->run($active, $this->ada->id, ['count' => 'two', 'evil' => 'x']));
        self::assertSame(['name' => 'Required.', 'count' => 'A number.', 'evil' => 'The form has no such input.'], $refused->errors);
        self::assertSame(ManualRunRefused::NOT_FOUND, $this->refusal(fn () => $this->runs->run($active, 'not-an-id', ['name' => 'Cat']))->getCode());
        self::assertSame([], $this->pets());
    }

    public function testItsStepsStillCheckTheirOwnPermissionsAndARunIsAllOrNothing(): void
    {
        // The clerk may run it, not delete people: its step, as the clerk, may not either.
        $workflow = $this->workflow('Pet then delete', <<<'YAML'
            trigger: { on: manual, object: person, label: Gone }
            steps:
              - { key: pet, type: create_record, object: pet, values: { name: Last, owner: '{{ $trigger.record.id }}' } }
              - { key: gone, type: delete_record, object: person, id: '{{ $trigger.record.id }}' }
            YAML);
        $this->give('clerk@example.com', $workflow);
        $this->signIn('clerk@example.com');

        $run = $this->runs->run($this->runs->get($workflow->getId()->toRfc4122()), $this->ada->id, []);

        self::assertSame([RunLog::FAILED, 'clerk@example.com does not hold record.person.delete.'], [$run->status, $run->error]);
        // The pet created first went with the run.
        self::assertSame([], $this->pets());
        self::assertNotNull(self::getContainer()->get(RecordFinderInterface::class)->find('person', $this->ada->id));
    }

    public function testARefuseKeepsNothing(): void
    {
        $workflow = $this->workflow('Refused', <<<'YAML'
            trigger: { on: manual, object: person, label: Try }
            steps:
              - { key: pet, type: create_record, object: pet, values: { name: Last, owner: '{{ $trigger.record.id }}' } }
              - { key: no, type: refuse, message: 'Not {{ $trigger.record.full_name }}.' }
            YAML);
        $this->give('owner@example.com', $workflow);

        $run = $this->runs->run($this->runs->get($workflow->getId()->toRfc4122()), $this->ada->id, []);

        self::assertSame([RunLog::FAILED, 'Not Ada.'], [$run->status, $run->error]);
        self::assertSame([], $this->pets());
    }

    public function testACommandRunsItAsNoOneWhoseWritesNeedARole(): void
    {
        self::getContainer()->get(RbacManagerInterface::class)->assign('owner@example.com', 'ROLE_CLERK');
        $this->workflow('Pet', self::RENAME);
        $this->workflow('Pet as clerk', str_replace("owner: '{{ \$trigger.record.id }}' } }", "owner: '{{ \$trigger.record.id }}' }, act_as: ROLE_CLERK }", self::RENAME));
        $command = new CommandTester((new Application(self::bootKernel()))->find('workflow:run'));

        self::assertSame(1, $command->execute(['workflow' => 'Pet', '--record' => $this->ada->id, '--input' => ['name=Cat']]));
        self::assertMatchesRegularExpression('/No\s+one\s+triggered\s+this\s+event/', $command->getDisplay());
        self::assertSame(2, $command->execute(['workflow' => 'Pet as clerk', '--input' => ['name']]));
        self::assertSame(1, $command->execute(['workflow' => 'Pet as clerk', '--record' => $this->ada->id]));
        self::assertStringContainsString('name: Required.', $command->getDisplay());

        self::assertSame(0, $command->execute(['workflow' => 'Pet as clerk', '--record' => $this->ada->id, '--input' => ['name=Dog', 'count=3']]), $command->getDisplay());
        self::assertSame(['Dog of Ada'], $this->pets());
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
        return array_map(static fn (RecordOutput $record): mixed => $record->get('name'), iterator_to_array(self::getContainer()->get(RecordFinderInterface::class)->each('pet'), false));
    }
}
