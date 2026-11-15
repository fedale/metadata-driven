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
use App\Module\Workflow\Application\ManualRuns;
use App\Module\Workflow\Application\WorkflowManager;
use App\Tests\Support\CreatesRelatedObjects;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * A search and an iterator over what it found, run by hand on a person: their pets renamed one by
 * one. The owner activates the workflows and runs them, holding every permission on person and
 * pet; the clerk may run them, reading people and creating pets only.
 */
final class SearchAndIteratorTest extends KernelTestCase
{
    use CreatesRelatedObjects;

    private const string RENAME_PETS = <<<'YAML'
        trigger: { on: manual, object: person, label: Rename the pets }
        steps:
          - key: pets
            type: search_records
            object: pet
            where: [{ field: owner, op: eq, value: '{{ $trigger.record.id }}' }]
            sort: name
          - key: each
            type: iterator
            items: '{{ pets.records }}'
            steps:
              - { key: not_tom, type: filter, when: { field: each.item.name, op: neq, value: Tom } }
              - { key: renamed, type: update_record, object: pet, id: '{{ each.item.id }}', values: { name: '{{ each.index }}. {{ each.item.name }} of {{ $trigger.record.full_name }}' } }
          - { key: done, type: update_record, object: person, id: '{{ $trigger.record.id }}', values: { city: '{{ pets.total }} pets, {{ each.count }} seen' } }
        YAML;

    private RecordOutput $ada;

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
        $rbac->assign('clerk@example.com', 'record.person.read');
        $rbac->assign('clerk@example.com', 'record.person.update');
        $rbac->assign('clerk@example.com', 'record.pet.update');

        $this->signIn('owner@example.com');
        $records = $container->get(RecordManagerInterface::class);
        $this->ada = $records->create(new RecordInput('person', ['full_name' => 'Ada']));
        $bob = $records->create(new RecordInput('person', ['full_name' => 'Bob']));
        foreach (['Rex' => $this->ada, 'Tom' => $this->ada, 'Fido' => $this->ada, 'Max' => $bob] as $name => $owner) {
            $records->create(new RecordInput('pet', ['name' => $name, 'owner' => $owner->id]));
        }
    }

    public function testRunsItsStepsOnEachRecordFoundAFilterSkippingOne(): void
    {
        $run = $this->runAs('owner@example.com', self::RENAME_PETS);

        self::assertSame(RunLog::COMPLETED, $run->status, (string) $run->error);
        // Sorted by name: Fido, Rex, Tom; Tom skipped; Bob's Max untouched.
        self::assertSame(['0. Fido of Ada', '1. Rex of Ada', 'Max', 'Tom'], $this->pets());
        self::assertSame('3 pets, 3 seen', self::getContainer()->get(RecordFinderInterface::class)->find('person', $this->ada->id)?->get('city'));
        self::assertSame(['pets', 'each', 'not_tom', 'renamed', 'not_tom', 'renamed', 'not_tom', 'done'], array_column($run->steps, 'key'));
    }

    public function testASearchReadsOnlyWhatWhoeverRunsItMayRead(): void
    {
        // The clerk may not read pets: the search, as the clerk, may not either.
        $run = $this->runAs('clerk@example.com', self::RENAME_PETS);

        self::assertSame([RunLog::FAILED, 'clerk@example.com does not hold record.pet.read.'], [$run->status, $run->error]);
        self::assertSame(['Fido', 'Max', 'Rex', 'Tom'], $this->pets());
    }

    public function testARefuseInsideEndsTheRunAndKeepsNothing(): void
    {
        $run = $this->runAs('owner@example.com', str_replace('{ key: not_tom, type: filter, when: { field: each.item.name, op: neq, value: Tom } }', "{ key: no_tom, type: if, branches: [{ when: { field: each.item.name, op: eq, value: Tom }, steps: [{ key: stop, type: refuse, message: 'Not {{ each.item.name }}.' }] }] }", self::RENAME_PETS));

        self::assertSame([RunLog::FAILED, 'Not Tom.'], [$run->status, $run->error]);
        self::assertSame(['Fido', 'Max', 'Rex', 'Tom'], $this->pets());
    }

    public function testAnIteratorNeedsAList(): void
    {
        $run = $this->runAs('owner@example.com', <<<YAML
            trigger: { on: manual, object: person, label: Count }
            steps:
              - { key: none, type: iterator, items: '{{ \$trigger.record.city }}', steps: [{ key: a, type: filter, when: { field: none.item, op: empty } }] }
            YAML);
        self::assertSame([RunLog::FAILED, '{{ $trigger.record.city }} is no list: nothing to run on.'], [$run->status, $run->error]);
    }

    private function runAs(string $email, string $yaml): RunLog
    {
        $this->signIn('owner@example.com');
        $manager = self::getContainer()->get(WorkflowManager::class);
        $workflow = $manager->create('Rename '.uniqid(), null, $yaml);
        $manager->activate($workflow);
        self::getContainer()->get(RbacManagerInterface::class)->assign($email, Permissions::workflowRun($workflow->getId()->toRfc4122()));
        $this->signIn($email);
        $runs = self::getContainer()->get(ManualRuns::class);

        return $runs->run($runs->get($workflow->getId()->toRfc4122()), $this->ada->id, []);
    }

    private function signIn(string $email): void
    {
        $user = self::getContainer()->get(UserProvider::class)->loadUserByIdentifier($email);
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    /**
     * @return list<mixed> the pets' names, sorted
     *
     * @phpstan-impure the records change between two calls
     */
    private function pets(): array
    {
        $names = array_map(static fn (RecordOutput $pet): mixed => $pet->get('name'), iterator_to_array(self::getContainer()->get(RecordFinderInterface::class)->each('pet'), false));
        sort($names);

        return $names;
    }
}
