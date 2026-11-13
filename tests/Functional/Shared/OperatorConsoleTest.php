<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Module\User\Contract\UserManagerInterface;
use App\Shared\Infrastructure\Tenant\TenantRegistry;
use App\Shared\Tenant\Tenant;
use App\Tests\Support\SignsIn;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The console of the installation, console.localhost in the tests: its operators, its tenants
 * (docs/internal/tenant-admin-ui-plan.md, part B).
 */
final class OperatorConsoleTest extends WebTestCase
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

    public function testOnlyAnOperatorSignsInToTheConsole(): void
    {
        self::createUser('anna@example.com');
        $this->operator('olga@example.com');

        $this->signInOnTheConsole('anna@example.com');
        $this->client->followRedirect();
        self::assertSelectorExists('.md-flash--error', 'A member of a tenant is no operator.');

        $this->signInOnTheConsole('olga@example.com');
        self::assertResponseRedirects('/', 302);
        $this->client->followRedirect();
        self::assertResponseRedirects('/console', 302);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'default');
    }

    public function testTheConsoleAndTheTenantsKeepToTheirHosts(): void
    {
        $this->client->request('GET', '/console', server: ['HTTP_HOST' => 'localhost']);
        self::assertResponseStatusCodeSame(404);

        foreach (['/admin', '/admin/members', '/o/pet'] as $path) {
            $this->client->request('GET', $path, server: ['HTTP_HOST' => 'console.localhost']);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testCreatesATenantWithItsPermissionsAndAnInvitedAdmin(): void
    {
        $this->signedInOperator();

        $crawler = $this->client->request('GET', '/console/tenants/new');
        $this->client->submitForm('Create', ['new_tenant[slug]' => 'console', 'new_tenant[name]' => 'Console']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'is reserved');

        $this->client->submitForm('Create', ['new_tenant[slug]' => 'globex', 'new_tenant[name]' => 'Globex', 'new_tenant[admin_email]' => 'Dora@Example.com']);
        self::assertResponseRedirects('/console/tenants/globex', 303);
        $crawler = $this->client->followRedirect();

        $globex = $this->tenant('globex');
        self::assertStringStartsWith('http://globex.localhost/invitation/', (string) $crawler->filter('input[readonly]')->attr('value'));
        $connection = self::getContainer()->get(Connection::class);
        self::assertNotFalse($connection->fetchOne(\sprintf("SELECT 1 FROM %s.auth_item WHERE name = 'admin.members'", $globex->rbacSchema)), 'Its permissions.');
        self::assertSame(['ROLE_SUPER_ADMIN'], $connection->fetchFirstColumn(\sprintf("SELECT item_name FROM %s.auth_assignment WHERE user_id = 'dora@example.com'", $globex->rbacSchema)));
    }

    public function testSuspendsResumesAndDeletesATenantButNeverTheDefaultOne(): void
    {
        $this->signedInOperator();
        $this->client->request('GET', '/console/tenants/new');
        $this->client->submitForm('Create', ['new_tenant[slug]' => 'globex', 'new_tenant[name]' => 'Globex']);
        $this->client->followRedirect();

        $this->client->submitForm('Suspend');
        self::assertFalse($this->tenant('globex')->active);
        $this->client->followRedirect();
        $this->client->submitForm('Resume');
        self::assertTrue($this->tenant('globex')->active);

        $this->client->followRedirect();
        $this->client->submitForm('Delete the tenant', ['delete_tenant[confirm]' => 'globe']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--error', 'Not confirmed');
        $this->client->submitForm('Delete the tenant', ['delete_tenant[confirm]' => 'globex']);
        self::assertResponseRedirects('/console', 303);
        self::assertNull(self::getContainer()->get(TenantRegistry::class)->bySlug('globex'));

        $crawler = $this->client->request('GET', '/console/tenants/default');
        self::assertCount(0, $crawler->filter('form[name="delete_tenant"]'));
        $this->client->request('POST', '/console/tenants/default/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testMakesAMemberSuperAdminAndRemovesThem(): void
    {
        self::createUser('bruno@example.com');
        $this->signedInOperator();
        $key = substr(hash('sha256', 'bruno@example.com'), 0, 12);

        $crawler = $this->client->request('GET', '/console/tenants/default');
        $this->client->submit($crawler->filter(\sprintf('form[name="admin_%s"]', $key))->form());
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(['ROLE_SUPER_ADMIN'], $connection->fetchFirstColumn("SELECT item_name FROM rbac.auth_assignment WHERE user_id = 'bruno@example.com'"));

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter(\sprintf('form[name="remove_%s"]', $key))->form());
        self::assertNotContains('bruno@example.com', self::getContainer()->get(UserManagerInterface::class)->membersOf($this->tenant('default')->id));
    }

    public function testRevokingTheFlagSignsTheOperatorOut(): void
    {
        $this->signedInOperator();
        self::getContainer()->get(UserManagerInterface::class)->setOperator('olga@example.com', false);

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

    private function operator(string $email): void
    {
        self::createUser($email);
        self::getContainer()->get(UserManagerInterface::class)->setOperator($email, true);
    }

    private function signedInOperator(): void
    {
        $this->operator('olga@example.com');
        $this->signInOnTheConsole('olga@example.com');
        self::assertResponseRedirects('/', 302);
    }

    private function signInOnTheConsole(string $email): void
    {
        $this->client->setServerParameter('HTTP_HOST', 'console.localhost');
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => $email, 'password' => self::PASSWORD]);
    }

    private function tenant(string $slug): Tenant
    {
        $tenant = self::getContainer()->get(TenantRegistry::class)->bySlug($slug);
        self::assertInstanceOf(Tenant::class, $tenant);

        return $tenant;
    }
}
