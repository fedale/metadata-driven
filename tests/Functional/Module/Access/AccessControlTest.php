<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Access;

use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\RecordAction;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\CreatesPetObject;
use App\Tests\Support\SignsIn;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Fedale\RbacBundle\Enum\AuthItemType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sign-in, and the RBAC permissions of every record action and of the admin.
 */
final class AccessControlTest extends WebTestCase
{
    use CreatesPetObject;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::createPetObject(self::getContainer());
        self::getContainer()->get(CreateObjectHandler::class)(new CreateObjectCommand('secret', 'Secret', 'Secrets'));
    }

    public function testAnonymousVisitorsAreSentToTheLoginForm(): void
    {
        foreach (['/', '/o/pet', '/admin'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/login', 302, $url);
        }
    }

    public function testSignsInWithEmailAndPassword(): void
    {
        self::createUser('Anna@Example.com');

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => 'anna@example.com', 'password' => 'wrong password here']);
        $this->client->followRedirect();
        self::assertSelectorExists('.md-flash--error');

        $this->client->submitForm('Sign in', ['email' => 'ANNA@example.com', 'password' => self::PASSWORD]);
        self::assertResponseRedirects('/', 302);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-topbar__user', 'Ada Lovelace');
        // Signed in but without any permission: nothing to open, no admin link.
        self::assertSelectorTextContains('main', 'ask an administrator for access');
        self::assertSelectorNotExists('.md-topbar__nav a[href="/admin"]');

        $this->client->clickLink('Sign out');
        $this->client->request('GET', '/o/pet');
        self::assertResponseRedirects('/login', 302);
    }

    public function testEveryRecordActionNeedsItsPermission(): void
    {
        $rex = $this->pet('Rex');
        self::signIn($this->client, 'reader@example.com', [Permissions::record('pet', RecordAction::Read)]);

        // Read: the grid, the record, the lookups and the export.
        $this->client->request('GET', '/');
        self::assertSame(['Pets'], $this->client->getCrawler()->filter('.md-link-list a')->each(static fn ($link): string => $link->text()));
        $this->client->request('GET', '/o/pet');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/o/pet/new"]');
        foreach (['/o/pet/lookup', '/o/pet/options', '/o/pet/export?format=csv'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }

        // The record opens read-only: disabled controls, no save, no delete.
        $crawler = $this->client->request('GET', \sprintf('/o/pet/%s/edit', $rex->id));
        self::assertResponseIsSuccessful();
        self::assertSame('disabled', $crawler->filter('input[name="record[name]"]')->attr('disabled'));
        self::assertSelectorNotExists('button:contains("Save")');
        self::assertSelectorNotExists('button:contains("Delete")');

        // Everything else is refused.
        foreach ([
            ['GET', '/o/pet/new'],
            ['POST', \sprintf('/o/pet/%s/edit', $rex->id)],
            ['POST', \sprintf('/o/pet/%s/delete', $rex->id)],
            ['GET', '/o/secret'],
            ['GET', '/o/secret/lookup'],
            ['GET', '/admin'],
        ] as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(403, $method.' '.$url);
        }
    }

    public function testRolesGrantPermissionsThroughTheHierarchy(): void
    {
        $rbac = self::getContainer()->get(RbacManagerInterface::class);
        // Roles defined at runtime, one inheriting the other: no code, no security.yaml.
        $rbac->addItem('ROLE_PET_READER', AuthItemType::ROLE);
        $rbac->addChild('ROLE_PET_READER', Permissions::record('pet', RecordAction::Read));
        $rbac->addItem('ROLE_PET_EDITOR', AuthItemType::ROLE);
        $rbac->addChild('ROLE_PET_EDITOR', 'ROLE_PET_READER');
        $rbac->addChild('ROLE_PET_EDITOR', Permissions::record('pet', RecordAction::Create));
        self::signIn($this->client, 'editor@example.com', ['ROLE_PET_EDITOR']);

        $this->client->request('GET', '/o/pet');
        self::assertSelectorExists('a[href="/o/pet/new"]');
        $this->client->request('GET', '/o/pet/new');
        $this->client->submitForm('Save', ['record[name]' => 'Fido']);
        self::assertResponseRedirects('/o/pet', 303);

        $fido = $this->pet('Rex');
        $this->client->request('GET', \sprintf('/o/pet/%s/edit', $fido->id));
        self::assertSelectorNotExists('button:contains("Save")');

        // A permission granted to the role applies on the next request, without signing in again.
        self::getContainer()->get(RbacManagerInterface::class)->addChild('ROLE_PET_EDITOR', Permissions::record('pet', RecordAction::Update));
        $this->client->request('GET', \sprintf('/o/pet/%s/edit', $fido->id));
        self::assertSelectorExists('button:contains("Save")');

        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheAdminNeedsItsPermission(): void
    {
        self::signIn($this->client, 'builder@example.com', [Permissions::ADMINISTER]);

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.md-topbar__nav a[href="/admin"]');
        // Managing the objects does not open their records.
        $this->client->request('GET', '/o/pet');
        self::assertResponseStatusCodeSame(403);
    }

    private function pet(string $name): RecordOutput
    {
        return self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('pet', ['name' => $name]));
    }
}
