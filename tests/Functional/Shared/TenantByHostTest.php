<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Tests\Support\CreatesTenants;
use App\Tests\Support\SignsIn;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A request is for the tenant its host names: <slug>.localhost in the tests, the default
 * tenant for localhost itself.
 */
final class TenantByHostTest extends WebTestCase
{
    use CreatesTenants;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testEachHostShowsItsTenantsObjects(): void
    {
        $container = self::getContainer();
        $container->get(MetadataManagerInterface::class)->createObject('pet', 'Pet', 'Pets');
        $globex = self::createTenant($container, 'globex', 'Globex');
        self::useTenant($container, $globex);
        $container->get(MetadataManagerInterface::class)->createObject('gadget', 'Gadget', 'Gadgets');
        // The super admin role is the tenant's: granted in Globex, as the client signs in there.
        $this->client->setServerParameter('HTTP_HOST', 'globex.localhost');
        self::signInAsSuperAdmin($this->client);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Gadgets');
        self::assertSelectorTextNotContains('body', 'Pets');
    }

    public function testTheBaseHostIsTheDefaultTenants(): void
    {
        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'localhost']);

        self::assertResponseStatusCodeSame(302, 'Not a 404: the sign-in page.');
    }

    public function testAHostNamingNoTenantIsNotFound(): void
    {
        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'initech.localhost']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'eu.globex.localhost']);
        self::assertResponseStatusCodeSame(404, 'A tenant is one label.');
    }

    public function testASuspendedTenantIsNotFound(): void
    {
        self::createTenant(self::getContainer(), 'globex', 'Globex');
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE core.tenant SET status = 'suspended' WHERE slug = 'globex'");

        $this->client->request('GET', '/', server: ['HTTP_HOST' => 'globex.localhost']);

        self::assertResponseStatusCodeSame(404);
    }
}
