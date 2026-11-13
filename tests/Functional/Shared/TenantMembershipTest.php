<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Module\File\Contract\FileStorageInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Shared\Infrastructure\Tenant\TenantRegistry;
use App\Shared\Tenant\Tenant;
use App\Tests\Support\CreatesTenants;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Users are global; a membership lets them into a tenant. Phase 3 of the multi-tenancy plan.
 */
final class TenantMembershipTest extends WebTestCase
{
    use CreatesTenants;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAUserSignsInToTheTenantsTheyAreAMemberOfOnly(): void
    {
        self::createUser('anna@example.com');                    // a member of the default tenant
        self::createTenant(self::getContainer(), 'globex', 'Globex');

        $this->client->setServerParameter('HTTP_HOST', 'globex.localhost');
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => 'anna@example.com', 'password' => self::PASSWORD]);
        $this->client->followRedirect();
        self::assertSelectorExists('.md-flash--error', 'The same error as a wrong password.');

        $this->client->setServerParameter('HTTP_HOST', 'localhost');
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => 'anna@example.com', 'password' => self::PASSWORD]);
        self::assertResponseRedirects('/', 302);
    }

    public function testASessionEndsWhenItsMembershipIsRemoved(): void
    {
        self::signIn($this->client, 'anna@example.com');
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        self::getContainer()->get(UserManagerInterface::class)->removeFromTenant('anna@example.com', $this->tenant('default')->id);
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login', 302);
    }

    public function testASessionReplayedOnAnotherTenantsHostIsSignedOut(): void
    {
        $container = self::getContainer();
        $globex = self::createTenant($container, 'globex', 'Globex');
        self::useTenant($container, $globex);
        // A member of Globex only.
        $this->client->setServerParameter('HTTP_HOST', 'globex.localhost');
        self::signIn($this->client, 'anna@example.com');
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        // The test client's session cookie has no domain: it goes to any host, as a cookie
        // copied from one host to another would.
        self::assertNotNull($this->client->getCookieJar()->get('MOCKSESSID'));
        $this->client->setServerParameter('HTTP_HOST', 'localhost');
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login', 302);
    }

    /**
     * A member of both tenants: the membership lets them in either, but a session is bound to
     * the tenant it was opened in. They sign in to each on its own.
     */
    public function testASessionIsBoundToItsTenantEvenForAMemberOfBoth(): void
    {
        $container = self::getContainer();
        $globex = self::createTenant($container, 'globex', 'Globex');
        self::createUser('anna@example.com');                                  // a member of the default tenant
        $container->get(UserManagerInterface::class)->addToTenant('anna@example.com', $globex->id);
        self::useTenant($container, $globex);
        $this->client->setServerParameter('HTTP_HOST', 'globex.localhost');
        $this->client->loginUser($container->get(UserProvider::class)->loadUserByIdentifier('anna@example.com'));
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $this->client->setServerParameter('HTTP_HOST', 'localhost');
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login', 302);
    }

    /**
     * "Keep me signed in" too: the cookie of one tenant signs no one in to another.
     */
    public function testARememberMeCookieIsBoundToItsTenant(): void
    {
        $container = self::getContainer();
        $globex = self::createTenant($container, 'globex', 'Globex');
        self::createUser('anna@example.com');
        $container->get(UserManagerInterface::class)->addToTenant('anna@example.com', $globex->id);

        $this->client->setServerParameter('HTTP_HOST', 'globex.localhost');
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => 'anna@example.com', 'password' => self::PASSWORD, '_remember_me' => true]);
        self::assertResponseRedirects('/', 302);
        $jar = $this->client->getCookieJar();
        $rememberMe = $jar->get('REMEMBERME', '/', 'globex.localhost');
        self::assertInstanceOf(Cookie::class, $rememberMe);

        // Only the remember-me cookie, as another host would get it: no session.
        $jar->clear();
        $jar->set(new Cookie('REMEMBERME', $rememberMe->getValue(), null, '/', 'localhost'));
        $this->client->setServerParameter('HTTP_HOST', 'localhost');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login', 302);

        // On its own host, it still signs her in.
        $jar->clear();
        $jar->set(new Cookie('REMEMBERME', $rememberMe->getValue(), null, '/', 'globex.localhost'));
        $this->client->setServerParameter('HTTP_HOST', 'globex.localhost');
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testAFileOfAnotherTenantIsNotFound(): void
    {
        $container = self::getContainer();
        $globex = self::createTenant($container, 'globex', 'Globex');
        self::useTenant($container, $globex);
        $path = tempnam(sys_get_temp_dir(), 'tenant');
        self::assertIsString($path);
        try {
            file_put_contents($path, 'Globex quarterly report');
            $theirs = $container->get(FileStorageInterface::class)->store($path, 'report.pdf');
        } finally {
            unlink($path);
        }
        self::useTenant($container, $this->tenant('default'));
        self::signInAsSuperAdmin($this->client);

        $this->client->request('GET', '/files/'.$theirs->id);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheTopBarLinksTheTenantsOfTheUser(): void
    {
        self::signIn($this->client, 'anna@example.com');
        self::createTenant(self::getContainer(), 'globex', 'Globex');
        self::getContainer()->get(UserManagerInterface::class)->addToTenant('anna@example.com', $this->tenant('globex')->id);

        $this->client->request('GET', '/');

        self::assertSelectorExists('.md-tenants a[href="http://globex.localhost/"]');
        self::assertSelectorTextContains('.md-tenants__option--current', 'Default');
    }

    private function tenant(string $slug): Tenant
    {
        $tenant = self::getContainer()->get(TenantRegistry::class)->bySlug($slug);
        self::assertInstanceOf(Tenant::class, $tenant);

        return $tenant;
    }
}
