<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Workflow;

use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Workflow\Domain\Repository\WorkflowRepository;
use App\Tests\Support\CreatesRelatedObjects;
use App\Tests\Support\SignsIn;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The workflows page: a draft written in YAML, checked when activated, its versions and runs.
 */
final class WorkflowAdminControllerTest extends WebTestCase
{
    use CreatesRelatedObjects;
    use SignsIn;

    private const string WELCOME = <<<'YAML'
        trigger: { on: created, object: person, when: after }
        steps:
          - { key: pet, type: create_record, object: pet, values: { name: 'Pet of {{ $trigger.record.full_name }}' } }
        YAML;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::createRelatedObjects(self::getContainer());
    }

    public function testOnlyWhoHoldsAdminWorkflowsSeesThePage(): void
    {
        self::signIn($this->client, 'anna@example.com', ['admin.metadata']);
        $this->client->request('GET', '/admin/workflows');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin');
        self::assertSelectorNotExists('a[href="/admin/workflows"]');
    }

    public function testShowsTheMonthsStepsAgainstThePlan(): void
    {
        self::signInAsSuperAdmin($this->client);
        $this->client->request('GET', '/admin/workflows');
        // No limit (the default plan): nothing to say.
        self::assertSelectorNotExists('.md-workflow-usage');

        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("UPDATE core.workspace SET plan = 'starter'");
        $this->client->request('GET', '/admin/workflows');
        self::assertSelectorTextContains('.md-workflow-usage', 'This month: 0 workflow steps of the 5000 your plan (Starter) allows.');

        $connection->executeStatement('INSERT INTO core.workflow_usage (workspace_id, day, steps) SELECT id, CURRENT_DATE, 4500 FROM core.workspace');
        $this->client->request('GET', '/admin/workflows');
        self::assertSelectorTextContains('.md-workflow-usage', '90%');

        $connection->executeStatement('UPDATE core.workflow_usage SET steps = 5000');
        $this->client->request('GET', '/admin/workflows');
        self::assertSelectorTextContains('[role="alert"].md-workflow-usage', 'No run starts until next month');
    }

    public function testWritesADraftShowsItsProblemsActivatesItAndShowsItsRuns(): void
    {
        self::signInAsSuperAdmin($this->client);
        $crawler = $this->client->request('GET', '/admin');
        $this->client->click($crawler->selectLink('Workflows')->link());
        $this->client->clickLink('New workflow');
        $this->client->submitForm('Create the draft', ['workflow[name]' => 'Welcome', 'workflow[definition]' => "trigger: { on: created, object: dragon, when: after }\nsteps: [{ key: a, type: filter, when: { field: \$trigger.record.x, op: empty } }]"]);
        self::assertResponseRedirects();
        $edit = (string) $this->client->getResponse()->headers->get('Location');
        $this->client->followRedirect();
        $this->yaml();
        self::assertSelectorTextContains('body', 'Not running: activate the draft.');

        // Saved as a draft, refused on activation, every problem listed by its path.
        $this->client->submitForm('Save and activate');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.md-problems', 'trigger.object: There is no object "dragon".');

        $this->client->submitForm('Save and activate', ['workflow[definition]' => self::WELCOME]);
        self::assertResponseRedirects($edit, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'Version 1 is active.');
        self::assertSelectorTextContains('body', 'Version 1 is running, activated by admin@example.com');

        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada']));

        $crawler = $this->client->request('GET', '/admin/workflows');
        self::assertSelectorTextContains('table', 'person, created, after');
        self::assertSelectorTextContains('table', 'Active: version 1');
        $this->client->click($crawler->filter('table a')->eq(1)->link());
        self::assertSelectorTextContains('table', 'Completed');
        $this->client->click($this->client->getCrawler()->filter('table a')->first()->link());
        self::assertSelectorTextContains('table', 'create_record');
        self::assertSelectorTextContains('pre', 'Pet of Ada');
    }

    public function testEditingAnActiveWorkflowMakesADraftAndAVersionComesBackAsDraft(): void
    {
        self::signInAsSuperAdmin($this->client);
        $this->client->request('GET', '/admin/workflows/new');
        $this->client->submitForm('Create the draft', ['workflow[name]' => 'Welcome', 'workflow[definition]' => self::WELCOME]);
        $this->client->followRedirect();
        $this->yaml();
        $this->client->submitForm('Save and activate');
        $this->client->followRedirect();
        $this->yaml();

        $this->client->submitForm('Save the draft', ['workflow[definition]' => str_replace('Pet of', 'Friend of', self::WELCOME)]);
        $this->client->followRedirect();
        $this->yaml();
        self::assertSelectorTextContains('body', 'You are editing the draft, version 2: the active version keeps running until you activate it.');
        $this->client->submitForm('Save and activate');
        $this->client->followRedirect();

        $this->client->clickLink('Versions');
        self::assertSelectorTextContains('table', 'Archived');
        $this->client->submitForm('Use as draft');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', "The draft is now version 1's definition.");
        $this->yaml();
        self::assertStringContainsString('Pet of', (string) $this->client->getCrawler()->filter('textarea')->text());

        $this->client->submit($this->client->getCrawler()->filter('form[name="deactivate_workflow"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Not running: activate the draft.');
    }

    public function testAChangeNeedsAValidToken(): void
    {
        self::signInAsSuperAdmin($this->client);
        $this->client->request('GET', '/admin/workflows/new');
        $this->client->submitForm('Create the draft', ['workflow[name]' => 'Welcome', 'workflow[definition]' => self::WELCOME]);
        $this->client->followRedirect();
        $this->yaml();
        $this->client->submitForm('Save and activate');
        $workflow = self::getContainer()->get(WorkflowRepository::class)->all()[0];
        $id = $workflow->getId()->toRfc4122();

        // A cross-site request: no form token, and an origin the stateless CSRF check refuses.
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://evil.example');
        $this->client->request('POST', '/admin/workflows/'.$id.'/deactivate', ['deactivate_workflow' => []]);
        $this->client->request('POST', '/admin/workflows/'.$id.'/delete', ['delete_workflow' => []]);

        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->client->request('GET', '/admin/workflows/'.$id);
        self::assertSelectorTextContains('body', 'Version 1 is running');
    }

    public function testOffersTheVariablesAndTestsTheDraftOnARecordKeepingNothing(): void
    {
        self::signInAsSuperAdmin($this->client);
        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada']));
        $this->client->request('GET', '/admin/workflows/new');
        $this->client->submitForm('Create the draft', ['workflow[name]' => 'Welcome', 'workflow[definition]' => self::WELCOME]);
        $this->client->followRedirect();
        $this->yaml();

        self::assertSelectorExists('button[data-variable-picker-path-param="$trigger.record.full_name"]');
        self::assertSelectorExists('button[data-variable-picker-path-param="pet.name"]');

        $crawler = $this->client->getCrawler();
        $ada = $crawler->filter('select[name="workflow[test_record]"] option')->eq(1)->attr('value');
        $this->client->submitForm('Test on this record', ['workflow[test_record]' => $ada]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#test', 'Rolled back: nothing was kept.');
        self::assertSelectorTextContains('#test pre', 'Pet of Ada');

        // Nothing kept: no pet, no run.
        $this->client->request('GET', '/o/pet');
        self::assertSelectorTextNotContains('body', 'Pet of Ada');
    }

    public function testTheTestShowsOnlyTheRecordsTheTesterMayRead(): void
    {
        self::signIn($this->client, 'anna@example.com', ['admin.workflows', 'record.pet.create']);
        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada']));
        $this->client->request('GET', '/admin/workflows/new');
        $this->client->submitForm('Create the draft', ['workflow[name]' => 'Welcome', 'workflow[definition]' => self::WELCOME]);
        $this->client->followRedirect();
        $this->yaml();

        // Anna may not read people: none is offered, and a test is refused.
        self::assertCount(1, $this->client->getCrawler()->filter('select[name="workflow[test_record]"] option'));
        $this->client->submitForm('Test on this record');
        self::assertSelectorTextContains('body', 'You may not read the records of "person": the test would show them.');
        self::assertSelectorNotExists('#test');
    }

    public function testTheListSetsTheOrderAndAWorkflowIsDeleted(): void
    {
        self::signInAsSuperAdmin($this->client);
        foreach (['First', 'Second'] as $name) {
            $this->client->request('GET', '/admin/workflows/new');
            $this->client->submitForm('Create the draft', ['workflow[name]' => $name, 'workflow[definition]' => self::WELCOME]);
        }

        $crawler = $this->client->request('GET', '/admin/workflows');
        $this->client->submit($crawler->selectButton('↓')->form());
        $this->client->followRedirect();
        self::assertSame(['Second', 'First'], array_map(static fn ($workflow): string => $workflow->getName(), self::getContainer()->get(WorkflowRepository::class)->all()));

        $this->client->clickLink('First');
        $this->client->submit($this->client->getCrawler()->filter('form[name="delete_workflow"]')->form());
        self::assertResponseRedirects('/admin/workflows', 303);
        self::assertSame(['Second'], array_map(static fn ($workflow): string => $workflow->getName(), self::getContainer()->get(WorkflowRepository::class)->all()));
    }

    /**
     * The YAML tab of the page shown: its form.
     */
    private function yaml(): void
    {
        $this->client->request('GET', strtok($this->client->getRequest()->getUri(), '?').'?mode=yaml');
    }
}
