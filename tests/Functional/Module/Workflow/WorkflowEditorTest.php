<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Workflow;

use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Domain\Repository\WorkflowRepository;
use App\Tests\Support\CreatesRelatedObjects;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The visual editor of a workflow's draft (a live component): steps added, moved and set, the
 * draft activated or its problems shown by step, the test.
 */
final class WorkflowEditorTest extends WebTestCase
{
    use CreatesRelatedObjects;
    use InteractsWithLiveComponents;
    use SignsIn;

    private const string WELCOME = <<<'YAML'
        # A comment the visual editor will not keep.
        trigger: { on: created, object: person, when: after }
        steps:
          - { key: pet, type: create_record, object: pet, values: { name: 'Pet of {{ $trigger.record.full_name }}' } }
        YAML;

    private KernelBrowser $client;

    private string $id;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::createRelatedObjects(self::getContainer());
        $this->id = self::getContainer()->get(WorkflowManager::class)->create('Welcome', null, self::WELCOME)->getId()->toRfc4122();
    }

    public function testBuildsTheStepsAndActivatesThem(): void
    {
        self::signInAsSuperAdmin($this->client);
        $editor = $this->editor();
        self::assertStringContainsString('Create a record', (string) $editor->render());

        $editor->call('addStep', ['at' => 'steps', 'type' => 'filter'])
            ->set('model.steps.1.when.tests.0.field', '$trigger.record.city')
            ->set('model.steps.1.when.tests.0.op', 'not_empty')
            ->call('moveStep', ['at' => 'steps.1', 'by' => -1])
            ->call('addValue', ['at' => 'steps.1'])
            ->set('model.steps.1.values.1.field', 'owner')
            ->set('model.steps.1.values.1.value', '{{ $trigger.record.id }}');
        // The filter is first now, the pet second.
        $html = (string) $editor->render();
        self::assertStringContainsString('data-model="model.steps.0.key" value="filter_1"', $html);
        self::assertStringContainsString('data-model="model.steps.1.key" value="pet"', $html);

        $editor->call('activate');
        self::assertSame(303, $editor->response()->getStatusCode());

        $workflow = self::getContainer()->get(WorkflowRepository::class)->findByName('Welcome');
        self::assertNotNull($workflow);
        $source = self::getContainer()->get(WorkflowManager::class)->activeVersion($workflow)?->getSource();
        self::assertStringNotContainsString('# A comment', (string) $source);

        // It runs: a person with a city gets a pet, owned.
        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada', 'city' => 'Pisa']));
        $this->client->request('GET', '/o/pet');
        self::assertSelectorTextContains('table', 'Pet of Ada');
    }

    public function testAManualTriggerHasALabelAndAForm(): void
    {
        self::signInAsSuperAdmin($this->client);
        $editor = $this->editor()
            ->set('model.trigger.on', 'manual')
            ->set('model.trigger.label', 'Give a pet')
            ->call('addInput')
            ->set('model.trigger.form.0.name', 'kind')
            ->set('model.trigger.form.0.label', 'Kind')
            ->set('model.trigger.form.0.type', 'select')
            ->set('model.trigger.form.0.options', 'cat, dog')
            ->set('model.steps.0.values.0.value', '{{ $trigger.input.kind }} of {{ $trigger.record.full_name }}');
        $html = (string) $editor->render();
        self::assertStringContainsString('data-model="model.trigger.form.0.options"', $html);
        self::assertStringNotContainsString('data-model="model.trigger.when"', $html);

        $editor->call('activate');
        self::assertSame(303, $editor->response()->getStatusCode());
        $workflow = self::getContainer()->get(WorkflowRepository::class)->findByName('Welcome');
        self::assertNotNull($workflow);
        self::assertStringContainsString("options:\n        - cat\n        - dog", (string) self::getContainer()->get(WorkflowManager::class)->activeVersion($workflow)?->getSource());
    }

    public function testBuildsASearchAndAnIteratorOverItsRecords(): void
    {
        self::signInAsSuperAdmin($this->client);
        $editor = $this->editor()
            ->call('removeStep', ['at' => 'steps.0'])
            ->call('addStep', ['at' => 'steps', 'type' => 'search_records'])
            ->set('model.steps.0.object', 'pet')
            ->call('addWhere', ['at' => 'steps.0'])
            ->set('model.steps.0.where.0.field', 'name')
            ->set('model.steps.0.where.0.op', 'not_empty')
            ->set('model.steps.0.limit', '20')
            ->call('addStep', ['at' => 'steps', 'type' => 'iterator'])
            ->set('model.steps.1.items', '{{ search_records_1.records }}')
            ->call('addStep', ['at' => 'steps.1.steps', 'type' => 'filter'])
            ->set('model.steps.1.steps.0.when.tests.0.field', 'iterator_1.item.name')
            ->set('model.steps.1.steps.0.when.tests.0.op', 'not_empty');
        self::assertStringContainsString('data-model="model.steps.1.steps.0.key" value="filter_1"', (string) $editor->render());

        $editor->call('activate');
        self::assertSame(303, $editor->response()->getStatusCode());
        $workflow = self::getContainer()->get(WorkflowRepository::class)->findByName('Welcome');
        self::assertNotNull($workflow);
        $source = (string) self::getContainer()->get(WorkflowManager::class)->activeVersion($workflow)?->getSource();
        self::assertStringContainsString('limit: 20', $source);
        self::assertStringContainsString("items: '{{ search_records_1.records }}'", $source);
    }

    public function testShowsTheProblemsOfTheDraftByStep(): void
    {
        self::signInAsSuperAdmin($this->client);
        // What it names, once its structure holds.
        $html = (string) $this->editor()->set('model.steps.0.object', 'dragon')->call('activate')->render();
        self::assertStringContainsString('steps.0.object: There is no object &quot;dragon&quot;.', $html);

        // Its structure: a new if's branches with no step yet, shown under that branch.
        $html = (string) $this->editor()->call('addStep', ['at' => 'steps', 'type' => 'if'])->call('activate')->render();
        self::assertStringContainsString('steps.1.branches.0.steps: Required: a list of steps, one at least.', $html);
        self::assertNull(self::getContainer()->get(WorkflowManager::class)->activeVersion(self::getContainer()->get(WorkflowRepository::class)->findByName('Welcome') ?? throw new \LogicException()));
    }

    public function testTestsTheDraftOnARecord(): void
    {
        self::signInAsSuperAdmin($this->client);
        $ada = self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada']));

        $html = (string) $this->editor()->set('testRecord', $ada->id)->call('runTest')->render();

        self::assertStringContainsString('Rolled back: nothing was kept.', $html);
        self::assertStringContainsString('Pet of Ada', $html);
    }

    public function testOnlyWhoHoldsAdminWorkflowsUsesIt(): void
    {
        self::signIn($this->client, 'anna@example.com', ['admin.metadata']);

        $this->expectException(AccessDeniedException::class);
        $this->editor()->render();
    }

    private function editor(): TestLiveComponent
    {
        return $this->createLiveComponent('WorkflowEditor', ['workflowId' => $this->id], $this->client);
    }
}
