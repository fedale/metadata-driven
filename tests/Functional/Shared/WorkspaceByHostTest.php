<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Tests\Support\CreatesWorkspaces;
use App\Tests\Support\SignsIn;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A request is for the workspace its host names: <slug>.localhost in the tests, the default
 * workspace for localhost itself.
 */
final class WorkspaceByHostTest extends WebTestCase
{
    use CreatesWorkspaces;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testEachHostShowsItsWorkspacesObjects(): void
    {
        $container = self::getContainer();
        $container->get(MetadataManagerInterface::class)->createObject('pet', 'Pet', 'Pets');
        $globex = self::createWorkspace($container, 'globex', 'Globex');
        self::useWorkspace($container, $globex);
        $container->get(MetadataManagerInterface::class)->createObject('gadget', 'Gadget', 'Gadgets');
        // The super admin role is the workspace's: granted in Globex, as the client signs in there.
        $this->client->setServerParameter('HTTP_HOST', 'globex.localhost');
        self::signInAsSuperAdmin($this->client);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Gadgets');
        self::assertSelectorTextNotContains('body', 'Pets');
    }

    public function testTheBaseHostIsTheDefaultWorkspaces(): void
    {
        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'localhost']);

        self::assertResponseStatusCodeSame(302, 'Not a 404: the sign-in page.');
    }

    public function testAHostNamingNoWorkspaceIsNotFound(): void
    {
        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'initech.localhost']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'eu.globex.localhost']);
        self::assertResponseStatusCodeSame(404, 'A workspace is one label.');
    }

    public function testASuspendedWorkspaceIsNotFound(): void
    {
        self::createWorkspace(self::getContainer(), 'globex', 'Globex');
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE core.workspace SET status = 'suspended' WHERE slug = 'globex'");

        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'globex.localhost']);

        self::assertResponseStatusCodeSame(404);
    }
}
