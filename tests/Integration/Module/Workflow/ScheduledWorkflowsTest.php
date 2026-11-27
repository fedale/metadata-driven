<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Workflow;

use App\Module\Access\Application\PermissionSynchronizer;
use App\Module\Access\Contract\Permissions;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Engine\Message\RunScheduled;
use App\Module\Workflow\Application\Engine\WorkflowEngine;
use App\Module\Workflow\Application\ScheduledWorkflows;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition;
use App\Module\Workflow\Domain\Model\Workflow;
use Doctrine\DBAL\Connection;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Workflows on a schedule: due at their cron expression's minutes (UTC), run as no one, their
 * steps as the roles they act as.
 */
final class ScheduledWorkflowsTest extends KernelTestCase
{
    private const string WEEKLY = <<<'YAML'
        trigger: { on: schedule, cron: '0 8 * * 1' }
        steps:
          - { key: note, type: create_record, object: note, values: { text: 'Week of {{ $trigger.scheduled_at }}' }, act_as: ROLE_SUPER_ADMIN }
        YAML;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $metadata = $container->get(MetadataManagerInterface::class);
        $metadata->createObject('note', 'Note', 'Notes');
        $metadata->createField('note', 'text', 'text', 'Text');
        $container->get(PermissionSynchronizer::class)->syncAll();
        $container->get(UserManagerInterface::class)->createUser('owner@example.com', 'correct horse battery staple');
        $container->get(RbacManagerInterface::class)->assign('owner@example.com', Permissions::SUPER_ADMIN_ROLE);
        $user = $container->get(UserProvider::class)->loadUserByIdentifier('owner@example.com');
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    public function testRunsAtItsMinutesAsNoOne(): void
    {
        $this->activate(self::WEEKLY);
        $scheduled = self::getContainer()->get(ScheduledWorkflows::class);

        // A Monday, 8:00 UTC (9:00 in Rome): due; a minute later, not.
        self::assertCount(1, $scheduled->queueDue(new \DateTimeImmutable('2026-11-16 09:00:30', new \DateTimeZone('Europe/Rome'))));
        self::assertSame([], $scheduled->queueDue(new \DateTimeImmutable('2026-11-16 08:01:00', new \DateTimeZone('UTC'))));

        self::assertSame(['Week of 2026-11-16T08:00:00+00:00'], $this->notes());
        $run = self::getContainer()->get(Connection::class)->fetchAssociative('SELECT status, timing, triggered_by FROM core.workflow_run');
        self::assertSame(['status' => 'completed', 'timing' => 'schedule', 'triggered_by' => null], $run);
    }

    public function testAVersionNoLongerActiveRunsNot(): void
    {
        $workflow = $this->activate(self::WEEKLY);
        $message = new RunScheduled('', $workflow->getId()->toRfc4122(), 'another version', '2026-11-16T08:00:00+00:00');

        self::assertNull(self::getContainer()->get(WorkflowEngine::class)->runScheduled($message));
        self::assertSame([], $this->notes());
    }

    public function testItsCronAndItsStepsRolesAreChecked(): void
    {
        try {
            (new DefinitionParser())->parse("trigger: { on: schedule, cron: 'every monday' }\nsteps: [{ key: a, type: refuse, message: no }]");
            self::fail('Refused.');
        } catch (InvalidWorkflowDefinition $e) {
            self::assertSame(['A cron expression, in UTC: minute hour day month weekday, e.g. "0 8 * * 1" (Mondays at 8).'], array_map(strval(...), $e->errors['trigger.cron']));
        }
        $manager = self::getContainer()->get(WorkflowManager::class);
        try {
            $manager->activate($manager->create('As no one', null, str_replace(', act_as: ROLE_SUPER_ADMIN', '', self::WEEKLY)));
            self::fail('Refused.');
        } catch (InvalidWorkflowDefinition $e) {
            self::assertSame(['No one is behind a schedule: a step that reads or writes records acts as a role you may give.'], array_map(strval(...), $e->errors['steps.0.act_as']));
        }
    }

    private function activate(string $yaml): Workflow
    {
        $manager = self::getContainer()->get(WorkflowManager::class);
        $workflow = $manager->create('Weekly', null, $yaml);
        $manager->activate($workflow);

        return $workflow;
    }

    /**
     * @return list<mixed>
     *
     * @phpstan-impure the records change between two calls
     */
    private function notes(): array
    {
        return array_map(static fn (RecordOutput $note): mixed => $note->get('text'), iterator_to_array(self::getContainer()->get(RecordFinderInterface::class)->each('note'), false));
    }
}
