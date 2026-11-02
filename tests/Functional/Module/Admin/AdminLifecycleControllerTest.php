<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Admin;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Tests\Support\CreatesRegistrationForm;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Renaming, archiving and restoring from the admin pages, as a browser would.
 */
final class AdminLifecycleControllerTest extends WebTestCase
{
    use CreatesRegistrationForm;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);
        self::createRegistrationForm(self::getContainer());
    }

    public function testRenamesAnObjectAndAField(): void
    {
        $crawler = $this->client->request('GET', '/admin/objects/profile');
        $this->client->submit($crawler->filter('form[name="rename"]')->form(['rename[name]' => 'person']));
        self::assertResponseRedirects('/admin/objects/person', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'Object "profile" is now "person", with its table. Rewritten to name the new one: composite form "registration"');
        self::assertSelectorTextContains('h1', 'person');

        $crawler = $this->client->request('GET', '/admin/objects/person/fields/city');
        $this->client->submit($crawler->filter('form[name="rename"]')->form(['rename[name]' => 'town']));
        self::assertResponseRedirects('/admin/objects/person/fields/town', 303);
        self::assertTrue($this->registry()->getObject('person')->hasField('town'));

        // A name taken is refused, and nothing changes.
        $crawler = $this->client->request('GET', '/admin/objects/person/fields/town');
        $this->client->submit($crawler->filter('form[name="rename"]')->form(['rename[name]' => 'zipcode']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--error', 'Object "person" already has a field named "zipcode".');
    }

    public function testArchivesAndRestoresAFieldAndAnObject(): void
    {
        // Listed by the composite form: refused, with the uses listed.
        $crawler = $this->client->request('GET', '/admin/objects/account/fields/lastname');
        $this->client->submit($crawler->filter('form[name="archive"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--error', 'Field "account.lastname" cannot be archived, it is still used: composite form "registration", (root): fields.');

        $crawler = $this->client->request('GET', '/admin/objects/profile/fields/birthdate');
        $this->client->submit($crawler->filter('form[name="archive"]')->form());
        self::assertResponseRedirects('/admin/objects/profile', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.md-archived', 'birthdate');
        $this->client->request('GET', '/admin/objects/profile/fields/birthdate');
        self::assertResponseStatusCodeSame(404);

        $this->client->submit($crawler->filter('.md-archived form')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'Field "birthdate" is back, with its values.');
        self::assertTrue($this->registry()->getObject('profile')->hasField('birthdate'));

        // An object nothing uses: archived, listed apart, restored.
        self::getContainer()->get(MetadataManagerInterface::class)->createObject('note', 'Note', 'Notes');
        $crawler = $this->client->request('GET', '/admin/objects/note');
        $this->client->submit($crawler->filter('form[name="archive"]')->form());
        self::assertResponseRedirects('/admin', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.md-admin__section', 'Archived');
        $this->client->request('GET', '/o/note');
        self::assertResponseStatusCodeSame(404);

        $this->client->submit($crawler->filter('form[name="restore"]')->form());
        self::assertResponseRedirects('/admin/objects/note', 303);
        $this->client->request('GET', '/o/note');
        self::assertResponseIsSuccessful();
    }

    private function registry(): MetadataRegistryInterface
    {
        return self::getContainer()->get(MetadataRegistryInterface::class);
    }
}
