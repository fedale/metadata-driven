<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Access;

use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\RecordAction;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\CreatesRelatedObjects;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Permissions where records of several objects meet: related lists, cascades, shared settings.
 */
final class RelatedAccessTest extends WebTestCase
{
    use CreatesRelatedObjects;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::createRelatedObjects(self::getContainer());
    }

    public function testRelatedRecordsOfUnreadableObjectsAreHidden(): void
    {
        $records = self::getContainer()->get(RecordManagerInterface::class);
        $anna = $records->create(new RecordInput('person', ['full_name' => 'Anna']));
        $records->create(new RecordInput('pet', ['name' => 'Rex', 'owner' => $anna->id]));
        self::signIn($this->client, 'hr@example.com', [Permissions::record('person', RecordAction::Read)]);

        $this->client->request('GET', \sprintf('/o/person/%s/edit', $anna->id));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.related');
        self::assertSelectorTextNotContains('main', 'Rex');
    }

    public function testACascadeNeedsTheDeletePermissionOfEveryObjectItReaches(): void
    {
        $records = self::getContainer()->get(RecordManagerInterface::class);
        $rex = $records->create(new RecordInput('pet', ['name' => 'Rex']));
        $visit = $records->create(new RecordInput('visit', ['notes' => 'Vaccine', 'pet' => $rex->id]));
        self::signIn($this->client, 'vet@example.com', [
            Permissions::record('pet', RecordAction::Read),
            Permissions::record('pet', RecordAction::Delete),
        ]);

        $this->client->request('POST', \sprintf('/o/pet/%s/delete', $rex->id));

        self::assertResponseRedirects(\sprintf('/o/pet/%s/edit', $rex->id), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'also deletes Visits, which you may not delete');
        $finder = self::getContainer()->get(RecordFinderInterface::class);
        self::assertNotNull($finder->find('pet', $rex->id));
        self::assertNotNull($finder->find('visit', $visit->id));
    }

    public function testACascadeGoesThroughWithEveryPermission(): void
    {
        $records = self::getContainer()->get(RecordManagerInterface::class);
        $rex = $records->create(new RecordInput('pet', ['name' => 'Rex']));
        $visit = $records->create(new RecordInput('visit', ['notes' => 'Vaccine', 'pet' => $rex->id]));
        self::signIn($this->client, 'vet@example.com', [
            Permissions::record('pet', RecordAction::Read),
            Permissions::record('pet', RecordAction::Delete),
            Permissions::record('visit', RecordAction::Delete),
        ]);

        $this->client->request('GET', \sprintf('/o/pet/%s/edit', $rex->id));
        $this->client->submitForm('Delete');

        self::assertResponseRedirects('/o/pet', 303);
        self::assertNull(self::getContainer()->get(RecordFinderInterface::class)->find('visit', $visit->id));
    }

    public function testTheSharedGridSettingsAreForAdministratorsOnly(): void
    {
        self::signIn($this->client, 'reader@example.com', [Permissions::record('pet', RecordAction::Read)]);

        $this->client->request('GET', '/o/pet');
        self::assertSelectorNotExists('[data-action="gridview-ui-settings#open"]');
        $this->client->request('GET', '/gridview/_settings/', ['scope' => 'record_pet']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/gridview/_settings/', ['scope' => '_global']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdministratorsReachTheGridSettings(): void
    {
        self::signIn($this->client, 'builder@example.com', [Permissions::ADMINISTER]);

        $this->client->request('GET', '/gridview/_settings/', ['scope' => '_global']);

        self::assertResponseIsSuccessful();
    }
}
