<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Workflow;

use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Engine\ActiveWorkflow;
use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\Engine\RunStore;
use App\Module\Workflow\Application\WorkflowManager;
use App\Tests\Support\CreatesRelatedObjects;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CollectRunsCommandTest extends KernelTestCase
{
    use CreatesRelatedObjects;

    public function testDeletesTheRunsOlderThanTheDaysTheyAreKept(): void
    {
        $container = self::getContainer();
        self::createRelatedObjects($container);
        $workflow = $container->get(WorkflowManager::class)->create('Kept', null, "trigger: { on: created, object: person, when: after }\nsteps: [{ key: a, type: refuse, message: no }]");
        $version = $container->get(Connection::class)->fetchOne('SELECT id FROM core.workflow_version WHERE workflow_id = ?', [$workflow->getId()->toRfc4122()]);
        self::assertIsString($version);
        $active = new ActiveWorkflow($workflow->getId()->toRfc4122(), 'Kept', $version, null, (new DefinitionParser())->parse("trigger: { on: created, object: person, when: after }\nsteps: [{ key: a, type: refuse, message: no }]"));
        foreach (['-40 days', '-2 days'] as $started) {
            $run = new RunLog($active, 'person', null, 'create', 'after', null, 1, new \DateTimeImmutable($started));
            $run->step('a', 'refuse', 'completed', ['message' => 'no'], null, null, 1);
            $run->end(RunLog::FAILED, 'no');
            $container->get(RunStore::class)->save($run);
        }

        $command = new CommandTester((new Application(self::bootKernel()))->find('workflow:runs:gc'));
        self::assertSame(0, $command->execute([]));
        self::assertStringContainsString('1 run(s) older than 30 days deleted.', $command->getDisplay());
        $connection = self::getContainer()->get(Connection::class);
        self::assertEquals(1, $connection->fetchOne('SELECT COUNT(*) FROM core.workflow_run'));
        // Its steps went with it.
        self::assertEquals(1, $connection->fetchOne('SELECT COUNT(*) FROM core.workflow_run_step'));

        self::assertSame(2, $command->execute(['--days' => '0']));
    }
}
