<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\CreatesRelatedObjects;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Relation fields through HTTP: the lookups, the form, the inverse side, the delete rules.
 */
final class RelationsControllerTest extends WebTestCase
{
    use SignsIn;
    use CreatesRelatedObjects;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);
        self::createRelatedObjects(self::getContainer());
    }

    public function testLookupEndpointsListRecordsByLabel(): void
    {
        $anna = $this->person('Anna Bianchi');
        $this->person('Bruno Rossi');

        $this->client->request('GET', '/o/person/lookup', ['query' => 'bian']);
        self::assertResponseIsSuccessful();
        self::assertSame(['results' => [['value' => $anna->id, 'text' => 'Anna Bianchi']]], $this->json());

        $this->client->request('GET', '/o/person/options');
        self::assertSame(['Anna Bianchi', 'Bruno Rossi'], array_column($this->json(), 'label'));
    }

    public function testCreatesARecordPointingAtAnother(): void
    {
        $anna = $this->person('Anna');

        $crawler = $this->client->request('GET', '/o/pet/new');
        $select = $crawler->filter('select[name="record[owner]"]');
        self::assertStringContainsString('autocomplete', (string) $select->attr('data-controller'));
        self::assertSame('/o/person/lookup', $select->attr('data-symfony--ux-autocomplete--autocomplete-url-value'));

        // The browser sends the id picked in the autocomplete, which the form did not list.
        $values = $crawler->selectButton('Save')->form()->getPhpValues();
        self::assertIsArray($values['record']);
        $values['record']['name'] = 'Rex';
        $values['record']['owner'] = $anna->id;
        $this->client->request('POST', '/o/pet/new', $values);
        self::assertResponseRedirects('/o/pet', 303);

        $this->client->followRedirect();
        self::assertSelectorTextSame('#gv-tbody-record_pet td[data-col-key="owner"]', 'Anna');
    }

    public function testEditPageShowsTheRecordsPointingAtIt(): void
    {
        $anna = $this->person('Anna');
        $rex = $this->records()->create(new RecordInput('pet', ['name' => 'Rex', 'owner' => $anna->id]));

        $crawler = $this->client->request('GET', \sprintf('/o/person/%s/edit', $anna->id));

        self::assertSelectorTextContains('.md-related h2', 'Pets');
        self::assertSame(\sprintf('/o/pet/%s/edit', $rex->id), $crawler->filter('.md-related a')->attr('href'));

        // The pet's own form shows the current owner by label.
        $crawler = $this->client->request('GET', \sprintf('/o/pet/%s/edit', $rex->id));
        self::assertSame('Anna', trim($crawler->filter('select[name="record[owner]"] option[selected]')->text()));
    }

    public function testARestrictedDeleteIsExplained(): void
    {
        $anna = $this->person('Anna');
        $this->records()->create(new RecordInput('invoice', ['number' => 'F-1', 'customer' => $anna->id]));

        $this->client->request('GET', \sprintf('/o/person/%s/edit', $anna->id));
        $this->client->submitForm('Delete');

        self::assertResponseRedirects(\sprintf('/o/person/%s/edit', $anna->id), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--error', 'cannot be deleted: 1 Invoices (Customer)');
        self::assertNotNull(self::getContainer()->get(RecordFinderInterface::class)->find('person', $anna->id));
    }

    private function person(string $name): RecordOutput
    {
        return $this->records()->create(new RecordInput('person', ['full_name' => $name]));
    }

    private function records(): RecordManagerInterface
    {
        return self::getContainer()->get(RecordManagerInterface::class);
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
