<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Workflow;

use App\Module\Workflow\Application\Secrets\WorkflowSecrets;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The page of the secrets: a value written once, never shown again.
 */
final class WorkflowSecretControllerTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testWritesAValueNeverShownAgainAndDeletesIt(): void
    {
        self::signIn($this->client, 'anna@example.com', ['admin.workflows']);
        $this->client->request('GET', '/admin/workflows');
        $this->client->clickLink('Secrets');
        $this->client->submitForm('Save the secret', ['secret[name]' => 'crm_token', 'secret[value]' => 'tok-very-secret']);
        self::assertResponseRedirects('/admin/workflows/secrets', 303);
        $this->client->followRedirect();

        self::assertSelectorTextContains('table', '$secrets.crm_token');
        self::assertStringNotContainsString('tok-very-secret', (string) $this->client->getResponse()->getContent());
        self::assertSame(['crm_token' => 'tok-very-secret'], self::getContainer()->get(WorkflowSecrets::class)->values());

        $this->client->submit($this->client->getCrawler()->filter('form[name="delete_secret_crm_token"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'Secret crm_token deleted');
        self::assertSame([], self::getContainer()->get(WorkflowSecrets::class)->names());
    }

    public function testOnlyWhoHoldsAdminWorkflows(): void
    {
        self::signIn($this->client, 'bob@example.com', ['admin.metadata']);
        $this->client->request('GET', '/admin/workflows/secrets');
        self::assertResponseStatusCodeSame(403);
    }
}
