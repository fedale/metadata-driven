<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Workflow;

use App\Module\User\Contract\UserManagerInterface;
use App\Shared\Infrastructure\Workspace\WorkspaceSwitcher;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The system admin's workflows, on the console's host: the admin's pages, on the platform's
 * events; a workspace's admins see none of them.
 */
final class ConsoleWorkflowControllerTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://console.localhost');
    }

    public function testTheSystemAdminDefinesAWorkflowOnAPlatformEvent(): void
    {
        self::createUser('olga@example.com');
        self::getContainer()->get(UserManagerInterface::class)->setSystemAdmin('olga@example.com', true);
        $this->client->setServerParameter('HTTP_HOST', 'console.localhost');
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => 'olga@example.com', 'password' => self::PASSWORD]);

        $this->client->request('GET', '/console/workflows');
        self::assertResponseIsSuccessful();
        $this->client->clickLink('New workflow');
        self::assertStringContainsString('workspace_created', (string) $this->client->getCrawler()->filter('textarea')->text());
        $this->client->submitForm('Create the draft', ['workflow[name]' => 'Clerks']);
        $this->client->followRedirect();
        // The visual editor offers the platform's events and steps only.
        self::assertSelectorExists('option[value="member_added"]');
        self::assertSelectorNotExists('option[value="created"]');
        self::assertSelectorExists('button[data-live-type-param="assign_role"]');
        self::assertSelectorNotExists('button[data-live-type-param="create_record"]');

        $this->client->request('GET', $this->client->getRequest()->getUri().'?mode=yaml');
        $this->client->submitForm('Save and activate', ['workflow[definition]' => "trigger: { on: member_added }\nsteps: [{ key: clerk, type: assign_role, email: '{{ \$trigger.member.email }}', role: ROLE_CLERK }]"]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'Version 1 is active.');
        $this->client->request('GET', '/console/workflows');
        self::assertSelectorTextContains('table', 'member_added');

        // A workspace's admin, on its host, sees none of it.
        $this->client->setServerParameter('HTTP_HOST', 'localhost');
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        // The test's own session, left in the console's: back to the tests' workspace.
        self::getContainer()->get(WorkspaceSwitcher::class)->leave();
        // A session belongs to where it was opened: the console's is no good here.
        $this->client->getCookieJar()->clear();
        self::signInAsSuperAdmin($this->client);
        $this->client->request('GET', '/admin/workflows');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Clerks');
    }
}
