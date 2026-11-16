<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Workflow;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Workflow\Domain\Repository\WorkflowRepository;
use App\Tests\Support\SignsIn;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A workflow on a webhook: its address made on its page, shown once; a request to it runs the
 * workflow, its body and query as $trigger; a wrong address finds nothing.
 */
final class WebhookTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $metadata = self::getContainer()->get(MetadataManagerInterface::class);
        $metadata->createObject('lead', 'Lead', 'Leads');
        $metadata->createField('lead', 'email', 'email', 'Email');
        $metadata->createField('lead', 'source', 'text', 'Source');
    }

    public function testAnAddressMadeOnceRunsTheWorkflow(): void
    {
        self::signInAsSuperAdmin($this->client);
        $this->client->request('GET', '/admin/workflows/new');
        $this->client->submitForm('Create the draft', ['workflow[name]' => 'Leads', 'workflow[definition]' => <<<'YAML'
            trigger: { on: webhook }
            steps:
              - { key: lead, type: create_record, object: lead, values: { email: '{{ $trigger.body.email }}', source: '{{ $trigger.query.from }}' }, act_as: ROLE_SUPER_ADMIN }
            YAML]);
        $this->client->followRedirect();
        $this->client->request('GET', $this->client->getRequest()->getUri().'?mode=yaml');
        $this->client->submitForm('Save and activate');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-workflow-webhook', 'No address yet');

        $this->client->submit($this->client->getCrawler()->filter('form[name="webhook_workflow"]')->form());
        $this->client->followRedirect();
        $flash = $this->client->getCrawler()->filter('.md-flash--success')->text();
        $address = preg_match('#http://localhost(/hooks/[0-9a-f-]{36}/[A-Za-z0-9_-]+)#', $flash, $match) ? $match[1] : self::fail($flash);
        // Shown once: not on the page again.
        $this->client->request('GET', $this->client->getRequest()->getUri());
        self::assertStringNotContainsString($address, (string) $this->client->getResponse()->getContent());

        // From outside: no one signed in.
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', $address.'?from=site', server: ['CONTENT_TYPE' => 'application/json'], content: '{"email": "ada@example.com"}');
        self::assertResponseStatusCodeSame(202);
        self::assertSame([['ada@example.com', 'site']], array_map(static fn (RecordOutput $lead): array => [$lead->get('email'), $lead->get('source')], iterator_to_array(self::getContainer()->get(RecordFinderInterface::class)->each('lead'), false)));
        self::assertSame('webhook', self::getContainer()->get(Connection::class)->fetchOne('SELECT timing FROM core.workflow_run'));

        // A wrong token, another workflow: nothing there.
        $this->client->request('POST', preg_replace('#/[A-Za-z0-9_-]+$#', '/'.str_repeat('x', 43), $address) ?? '', content: '{}');
        self::assertResponseStatusCodeSame(404);
        $workflow = self::getContainer()->get(WorkflowRepository::class)->findByName('Leads');
        self::assertNotNull($workflow);
        self::assertStringContainsString($workflow->getId()->toRfc4122(), $address);
    }

    public function testATextBodyAndTooManyRuns(): void
    {
        $address = $this->activeWebhook("trigger: { on: webhook }\nsteps: [{ key: lead, type: create_record, object: lead, values: { source: '{{ \$trigger.body }}' }, act_as: ROLE_SUPER_ADMIN }]");

        $this->client->request('POST', $address, content: 'plain words');
        self::assertResponseStatusCodeSame(202);
        self::assertSame(['plain words'], array_map(static fn (RecordOutput $lead): mixed => $lead->get('source'), iterator_to_array(self::getContainer()->get(RecordFinderInterface::class)->each('lead'), false)));

        // The workspace's runs a minute spent: refused, nothing queued.
        $limit = self::getContainer()->getParameter('app.workflow.runs_per_minute');
        self::getContainer()->get(Connection::class)->executeStatement(\sprintf('INSERT INTO core.workflow_run (id, workflow_id, version_id, status, object, action, timing, depth, chain, started_at, finished_at) SELECT gen_random_uuid(), workflow_id, version_id, status, object, action, timing, depth, chain, NOW(), NOW() FROM core.workflow_run, generate_series(1, %d)', $limit));
        $this->client->request('POST', $address, content: '{}');
        self::assertResponseStatusCodeSame(429);
    }

    public function testAStepNeedsARole(): void
    {
        self::signInAsSuperAdmin($this->client);
        $this->client->request('GET', '/admin/workflows/new');
        $this->client->submitForm('Create the draft', ['workflow[name]' => 'Leads', 'workflow[definition]' => "trigger: { on: webhook }\nsteps: [{ key: lead, type: create_record, object: lead, values: { email: x } }]"]);
        $this->client->followRedirect();
        $this->client->request('GET', $this->client->getRequest()->getUri().'?mode=yaml');
        $this->client->submitForm('Save and activate');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.md-problems', 'No one is behind a webhook');
    }

    /**
     * A workflow on a webhook, active, its address made: the path to post to.
     */
    private function activeWebhook(string $yaml): string
    {
        self::signInAsSuperAdmin($this->client);
        $this->client->request('GET', '/admin/workflows/new');
        $this->client->submitForm('Create the draft', ['workflow[name]' => 'Hook', 'workflow[definition]' => $yaml]);
        $this->client->followRedirect();
        $this->client->request('GET', $this->client->getRequest()->getUri().'?mode=yaml');
        $this->client->submitForm('Save and activate');
        $this->client->followRedirect();
        $this->client->submit($this->client->getCrawler()->filter('form[name="webhook_workflow"]')->form());
        $this->client->followRedirect();
        $flash = $this->client->getCrawler()->filter('.md-flash--success')->text();
        $address = preg_match('#http://localhost(/hooks/\S+)#', $flash, $match) ? $match[1] : self::fail($flash);
        $this->client->getCookieJar()->clear();

        return $address;
    }
}
