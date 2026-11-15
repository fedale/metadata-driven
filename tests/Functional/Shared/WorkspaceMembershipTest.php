<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Module\File\Contract\FileStorageInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Shared\Infrastructure\Workspace\WorkspaceRegistry;
use App\Shared\Workspace\Workspace;
use App\Tests\Support\CreatesWorkspaces;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Users are global; a membership lets them into a workspace. Phase 3 of the multi-tenancy plan.
 */
final class WorkspaceMembershipTest extends WebTestCase
{
    use CreatesWorkspaces;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAUserSignsInToTheWorkspacesTheyAreAMemberOfOnly(): void
    {
        self::createUser('anna@example.com');                    // a member of the default workspace
        self::createWorkspace(self::getContainer(), 'globex', 'Globex');

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

        self::getContainer()->get(UserManagerInterface::class)->removeFromWorkspace('anna@example.com', $this->workspace('default')->id);
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login', 302);
    }

    public function testASessionReplayedOnAnotherWorkspacesHostIsSignedOut(): void
    {
        $container = self::getContainer();
        $globex = self::createWorkspace($container, 'globex', 'Globex');
        self::useWorkspace($container, $globex);
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
     * A member of both workspaces: the membership lets them in either, but a session is bound to
     * the workspace it was opened in. They sign in to each on its own.
     */
    public function testASessionIsBoundToItsWorkspaceEvenForAMemberOfBoth(): void
    {
        $container = self::getContainer();
        $globex = self::createWorkspace($container, 'globex', 'Globex');
        self::createUser('anna@example.com');                                  // a member of the default workspace
        $container->get(UserManagerInterface::class)->addToWorkspace('anna@example.com', $globex->id);
        self::useWorkspace($container, $globex);
        $this->client->setServerParameter('HTTP_HOST', 'globex.localhost');
        $this->client->loginUser($container->get(UserProvider::class)->loadUserByIdentifier('anna@example.com'));
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $this->client->setServerParameter('HTTP_HOST', 'localhost');
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login', 302);
    }

    /**
     * "Keep me signed in" too: the cookie of one workspace signs no one in to another.
     */
    public function testARememberMeCookieIsBoundToItsWorkspace(): void
    {
        $container = self::getContainer();
        $globex = self::createWorkspace($container, 'globex', 'Globex');
        self::createUser('anna@example.com');
        $container->get(UserManagerInterface::class)->addToWorkspace('anna@example.com', $globex->id);

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

    public function testAFileOfAnotherWorkspaceIsNotFound(): void
    {
        $container = self::getContainer();
        $globex = self::createWorkspace($container, 'globex', 'Globex');
        self::useWorkspace($container, $globex);
        $path = tempnam(sys_get_temp_dir(), 'workspace');
        self::assertIsString($path);
        try {
            file_put_contents($path, 'Globex quarterly report');
            $theirs = $container->get(FileStorageInterface::class)->store($path, 'report.pdf');
        } finally {
            unlink($path);
        }
        self::useWorkspace($container, $this->workspace('default'));
        self::signInAsSuperAdmin($this->client);

        $this->client->request('GET', '/files/'.$theirs->id);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheTopBarLinksTheWorkspacesOfTheUser(): void
    {
        self::signIn($this->client, 'anna@example.com');
        self::createWorkspace(self::getContainer(), 'globex', 'Globex');
        self::getContainer()->get(UserManagerInterface::class)->addToWorkspace('anna@example.com', $this->workspace('globex')->id);

        $this->client->request('GET', '/');

        self::assertSelectorExists('.md-workspaces a[href="http://globex.localhost/"]');
        self::assertSelectorTextContains('.md-workspaces__option--current', 'Default');
    }

    private function workspace(string $slug): Workspace
    {
        $workspace = self::getContainer()->get(WorkspaceRegistry::class)->bySlug($slug);
        self::assertInstanceOf(Workspace::class, $workspace);

        return $workspace;
    }
}
