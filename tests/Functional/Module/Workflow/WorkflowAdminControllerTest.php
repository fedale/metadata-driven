<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Workflow;

use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Workflow\Domain\Repository\WorkflowRepository;
use App\Tests\Support\CreatesRelatedObjects;
use App\Tests\Support\SignsIn;
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
        $this->client->submitForm('Save and activate');
        $this->client->followRedirect();

        $this->client->submitForm('Save the draft', ['workflow[definition]' => str_replace('Pet of', 'Friend of', self::WELCOME)]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'You are editing the draft, version 2: the active version keeps running until you activate it.');
        $this->client->submitForm('Save and activate');
        $this->client->followRedirect();

        $this->client->clickLink('Versions');
        self::assertSelectorTextContains('table', 'Archived');
        $this->client->submitForm('Use as draft');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', "The draft is now version 1's definition.");
        self::assertStringContainsString('Pet of', (string) $this->client->getCrawler()->filter('textarea')->text());

        $this->client->submitForm('Deactivate');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Not running: activate the draft.');
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
        $this->client->submitForm('Delete');
        self::assertResponseRedirects('/admin/workflows', 303);
        self::assertSame(['Second'], array_map(static fn ($workflow): string => $workflow->getName(), self::getContainer()->get(WorkflowRepository::class)->all()));
    }
}
