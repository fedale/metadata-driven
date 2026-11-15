<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Module\User\Contract\UserManagerInterface;
use App\Shared\Infrastructure\Workspace\WorkspaceRegistry;
use App\Shared\Workspace\Workspace;
use App\Tests\Support\SignsIn;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The system console, console.localhost in the tests: its system admins, its workspaces
 * (docs/internal/tenant-admin-ui-plan.md, part B).
 */
final class SystemConsoleTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $limits = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $limits);
        $limits->clear();
    }

    /**
     * Their tries counted here (wrong passwords) must not count in the next test class either.
     */
    protected function tearDown(): void
    {
        $limits = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $limits);
        $limits->clear();
        parent::tearDown();
    }

    public function testOnlyASystemAdminSignsInToTheConsole(): void
    {
        self::createUser('anna@example.com');
        $this->systemAdmin('olga@example.com');

        $this->signInOnTheConsole('anna@example.com');
        $this->client->followRedirect();
        self::assertSelectorExists('.md-flash--error', 'A member of a workspace is no system admin.');

        $this->signInOnTheConsole('olga@example.com');
        self::assertResponseRedirects('/', 302);
        $this->client->followRedirect();
        self::assertResponseRedirects('/console', 302);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'default');
    }

    /**
     * Its pages have their styles and scripts: in development AssetMapper serves them through
     * the application, under /assets/.
     */
    public function testTheConsoleServesItsAssets(): void
    {
        $crawler = $this->client->request('GET', '/login', server: ['HTTP_HOST' => 'console.localhost']);
        $stylesheet = (string) $crawler->filter('link[rel="stylesheet"]')->first()->attr('href');
        self::assertStringStartsWith('/assets/', $stylesheet);

        $this->client->request('GET', $stylesheet, server: ['HTTP_HOST' => 'console.localhost']);

        self::assertResponseIsSuccessful();
    }

    public function testTheConsoleAndTheWorkspacesKeepToTheirHosts(): void
    {
        $this->client->request('GET', '/console', server: ['HTTP_HOST' => 'localhost']);
        self::assertResponseStatusCodeSame(404);

        foreach (['/admin', '/admin/members', '/o/pet'] as $path) {
            $this->client->request('GET', $path, server: ['HTTP_HOST' => 'console.localhost']);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testCreatesAWorkspaceWithItsPermissionsAndAnInvitedAdmin(): void
    {
        $this->signedInSystemAdmin();

        $crawler = $this->client->request('GET', '/console/workspaces/new');
        $this->client->submitForm('Create', ['new_workspace[slug]' => 'console', 'new_workspace[name]' => 'Console']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'is reserved');

        $this->client->submitForm('Create', ['new_workspace[slug]' => 'globex', 'new_workspace[name]' => 'Globex', 'new_workspace[admin_email]' => 'Dora@Example.com']);
        self::assertResponseRedirects('/console/workspaces/globex', 303);
        $crawler = $this->client->followRedirect();

        $globex = $this->workspace('globex');
        self::assertStringStartsWith('http://globex.localhost/invitation/', (string) $crawler->filter('input[readonly]')->attr('value'));
        $connection = self::getContainer()->get(Connection::class);
        self::assertNotFalse($connection->fetchOne(\sprintf("SELECT 1 FROM %s.auth_item WHERE name = 'admin.members'", $globex->rbacSchema)), 'Its permissions.');
        self::assertSame(['ROLE_SUPER_ADMIN'], $connection->fetchFirstColumn(\sprintf("SELECT item_name FROM %s.auth_assignment WHERE user_id = 'dora@example.com'", $globex->rbacSchema)));
    }

    public function testSuspendsResumesAndDeletesAWorkspaceButNeverTheDefaultOne(): void
    {
        $this->signedInSystemAdmin();
        $this->client->request('GET', '/console/workspaces/new');
        $this->client->submitForm('Create', ['new_workspace[slug]' => 'globex', 'new_workspace[name]' => 'Globex']);
        $this->client->followRedirect();

        $this->client->submitForm('Suspend');
        self::assertFalse($this->workspace('globex')->active);
        $this->client->followRedirect();
        $this->client->submitForm('Resume');
        self::assertTrue($this->workspace('globex')->active);

        $this->client->followRedirect();
        $this->client->submitForm('Delete the workspace', ['delete_workspace[confirm]' => 'globe']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--error', 'Not confirmed');
        $this->client->submitForm('Delete the workspace', ['delete_workspace[confirm]' => 'globex']);
        self::assertResponseRedirects('/console', 303);
        self::assertNull(self::getContainer()->get(WorkspaceRegistry::class)->bySlug('globex'));

        $crawler = $this->client->request('GET', '/console/workspaces/default');
        self::assertCount(0, $crawler->filter('form[name="delete_workspace"]'));
        $this->client->request('POST', '/console/workspaces/default/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testMakesAMemberSuperAdminAndRemovesThem(): void
    {
        self::createUser('bruno@example.com');
        $this->signedInSystemAdmin();
        $key = substr(hash('sha256', 'bruno@example.com'), 0, 12);

        $crawler = $this->client->request('GET', '/console/workspaces/default');
        $this->client->submit($crawler->filter(\sprintf('form[name="admin_%s"]', $key))->form());
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(['ROLE_SUPER_ADMIN'], $connection->fetchFirstColumn("SELECT item_name FROM rbac.auth_assignment WHERE user_id = 'bruno@example.com'"));

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter(\sprintf('form[name="remove_%s"]', $key))->form());
        self::assertNotContains('bruno@example.com', self::getContainer()->get(UserManagerInterface::class)->membersOf($this->workspace('default')->id));
    }

    /**
     * Every action of the console checks its CSRF token: a request forged elsewhere does nothing.
     */
    public function testAForgedRequestChangesNothing(): void
    {
        self::createUser('bruno@example.com');
        $this->signedInSystemAdmin();
        $key = substr(hash('sha256', 'bruno@example.com'), 0, 12);
        $default = $this->workspace('default');

        $this->client->request('POST', '/console/workspaces/default/status', ['status' => []]);
        $this->client->request('POST', \sprintf('/console/workspaces/default/members/%s/admin', $key), ['admin_'.$key => []]);
        $this->client->request('POST', \sprintf('/console/workspaces/default/members/%s/remove', $key), ['remove_'.$key => []]);

        self::assertTrue($this->workspace('default')->active);
        self::assertSame([], self::getContainer()->get(Connection::class)->fetchFirstColumn("SELECT item_name FROM rbac.auth_assignment WHERE user_id = 'bruno@example.com'"));
        self::assertContains('bruno@example.com', self::getContainer()->get(UserManagerInterface::class)->membersOf($default->id));
    }

    public function testRevokingTheFlagSignsTheSystemAdminOut(): void
    {
        $this->signedInSystemAdmin();
        self::getContainer()->get(UserManagerInterface::class)->setSystemAdmin('olga@example.com', false);

        $this->client->request('GET', '/console');

        self::assertResponseRedirects('/login', 302);
    }

    public function testSigningInIsThrottled(): void
    {
        self::createUser('anna@example.com');
        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('GET', '/login');
            $this->client->submitForm('Sign in', ['email' => 'anna@example.com', 'password' => 'not her password at all']);
        }

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => 'anna@example.com', 'password' => self::PASSWORD]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.md-flash--error', 'Too many');
    }

    private function systemAdmin(string $email): void
    {
        self::createUser($email);
        self::getContainer()->get(UserManagerInterface::class)->setSystemAdmin($email, true);
    }

    private function signedInSystemAdmin(): void
    {
        $this->systemAdmin('olga@example.com');
        $this->signInOnTheConsole('olga@example.com');
        self::assertResponseRedirects('/', 302);
    }

    private function signInOnTheConsole(string $email): void
    {
        $this->client->setServerParameter('HTTP_HOST', 'console.localhost');
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => $email, 'password' => self::PASSWORD]);
    }

    private function workspace(string $slug): Workspace
    {
        $workspace = self::getContainer()->get(WorkspaceRegistry::class)->bySlug($slug);
        self::assertInstanceOf(Workspace::class, $workspace);

        return $workspace;
    }
}
