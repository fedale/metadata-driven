<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Workflow;

use App\Module\Access\Application\PermissionSynchronizer;
use App\Module\Access\Contract\Permissions;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\ManualRuns;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Contract\WorkflowUsageInterface;
use App\Shared\Workspace\CurrentWorkspace;
use App\Tests\Support\CreatesRelatedObjects;
use Doctrine\DBAL\Connection;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The workflow steps a workspace's plan allows a month: counted from the day's usage, and past
 * them no run starts. The test workspace goes on the starter plan (5000 steps).
 */
final class WorkflowQuotaTest extends KernelTestCase
{
    use CreatesRelatedObjects;

    private Connection $connection;

    protected function setUp(): void
    {
        $container = self::getContainer();
        self::createRelatedObjects($container);
        $container->get(UserManagerInterface::class)->createUser('admin@example.com', 'correct horse battery staple', 'Ada', 'Lovelace');
        $container->get(PermissionSynchronizer::class)->syncAll();
        $container->get(RbacManagerInterface::class)->assign('admin@example.com', Permissions::SUPER_ADMIN_ROLE);
        $user = $container->get(UserProvider::class)->loadUserByIdentifier('admin@example.com');
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $this->connection = $container->get(Connection::class);
        $this->connection->executeStatement("UPDATE core.workspace SET plan = 'starter' WHERE id = ?", [$container->get(CurrentWorkspace::class)->id()]);
    }

    public function testTheMonthsStepsAreCountedAgainstThePlan(): void
    {
        $usage = self::getContainer()->get(WorkflowUsageInterface::class);
        self::assertSame([0, 5000, 'Starter'], [$usage->thisMonth()->steps, $usage->thisMonth()->limit, $usage->thisMonth()->plan]);

        $this->spend(4000);
        // Last month's steps count for nothing.
        $this->connection->executeStatement("INSERT INTO core.workflow_usage (day, steps) VALUES (date_trunc('month', CURRENT_DATE) - interval '1 day', 9000)");

        self::assertSame([4000, 80, false], [$usage->thisMonth()->steps, $usage->thisMonth()->percent(), $usage->thisMonth()->exhausted()]);
    }

    public function testPastThemNoRunStarts(): void
    {
        $manager = self::getContainer()->get(WorkflowManager::class);
        $manual = $manager->create('Pet', null, "trigger: { on: manual, object: person, label: Pet }\nsteps: [{ key: pet, type: create_record, object: pet, values: { name: 'Pet of {{ \$trigger.record.full_name }}' } }]");
        $manager->activate($manual);
        $manager->activate($manager->create('City', null, "trigger: { on: created, object: person, when: before }\nsteps: [{ key: city, type: set_values, values: { city: Torino } }]"));
        $records = self::getContainer()->get(RecordManagerInterface::class);
        $runs = self::getContainer()->get(ManualRuns::class);
        $ada = $records->create(new RecordInput('person', ['full_name' => 'Ada']));
        self::assertSame('Torino', $ada->get('city'));
        self::assertSame(RunLog::COMPLETED, $runs->run($runs->get($manual->getId()->toRfc4122()), $ada->id, [])->status);

        $this->spend(5000);

        $run = $runs->run($runs->get($manual->getId()->toRfc4122()), $ada->id, []);
        self::assertSame(RunLog::FAILED, $run->status);
        self::assertSame('This workspace ran the 5000 workflow steps its plan (Starter) allows a month: no run starts until next month.', $run->error);
        // A workflow guarding a write before it: the write is refused.
        try {
            $records->create(new RecordInput('person', ['full_name' => 'Bea']));
            self::fail('Refused.');
        } catch (RecordValidationFailed $e) {
            self::assertStringContainsString('allows a month', implode(' ', array_map(strval(...), $e->errors[''])));
        }

        // Another plan: they run again.
        $this->connection->executeStatement("UPDATE core.workspace SET plan = 'business'");
        self::assertSame('Torino', $records->create(new RecordInput('person', ['full_name' => 'Bea']))->get('city'));
    }

    private function spend(int $steps): void
    {
        $this->connection->executeStatement('INSERT INTO core.workflow_usage (day, steps) VALUES (CURRENT_DATE, ?) ON CONFLICT (workspace_id, day) DO UPDATE SET steps = core.workflow_usage.steps + EXCLUDED.steps', [$steps]);
    }
}
