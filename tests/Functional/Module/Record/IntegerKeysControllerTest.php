<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\Metadata\Contract\Dto\KeyType;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Module\Workflow\Application\WorkflowManager;
use App\Tests\Support\SignsIn;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Objects with an integer key, as a user meets them: made from the admin and the console, their
 * records' pages at /o/<object>/<number>, the lookups, the rows of a junction, a workflow run.
 */
final class IntegerKeysControllerTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);
    }

    public function testTheAdminMakesAnObjectWithAnIntegerKey(): void
    {
        $crawler = $this->client->request('GET', '/admin/objects/new');
        self::assertSame('uuid', $crawler->filter('input[name="object[keyType]"][checked]')->attr('value'));
        $this->client->submitForm('Create', ['object[name]' => 'ticket', 'object[labelSingular]' => 'Ticket', 'object[labelPlural]' => 'Tickets', 'object[keyType]' => 'integer']);
        self::assertResponseRedirects('/admin/objects/ticket', 303);

        self::assertSame(KeyType::Integer, $this->registry()->getObject('ticket')->keyType);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Integer, numbered by the database');
    }

    public function testTheConsoleMakesAnObjectWithAnIntegerKey(): void
    {
        $command = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('metadata:object:create'));

        self::assertSame(Command::SUCCESS, $command->execute(['name' => 'ticket', 'label-singular' => 'Ticket', 'label-plural' => 'Tickets', '--key' => 'integer']));
        self::assertSame(KeyType::Integer, $this->registry()->getObject('ticket')->keyType);
        self::assertSame(Command::INVALID, $command->execute(['name' => 'note', 'label-singular' => 'Note', 'label-plural' => 'Notes', '--key' => 'serial']));
        self::assertStringContainsString('"serial" is no key type', $command->getDisplay());
    }

    public function testTheRecordPagesTakeTheNumbers(): void
    {
        $this->teams();
        $red = $this->create('team', ['name' => 'Red']);
        $ada = $this->create('player', ['name' => 'Ada', 'team' => $red]);
        $this->create('player', ['name' => 'Bea']);

        $this->client->request('GET', '/o/player');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Ada');
        self::assertSelectorTextContains('body', 'Red');

        $crawler = $this->client->request('GET', \sprintf('/o/player/%s/edit', $ada));
        self::assertResponseIsSuccessful();
        $this->post(\sprintf('/o/player/%s/edit', $ada), $crawler, ['name' => 'Ada Lovelace']);
        self::assertResponseRedirects();
        self::assertSame('Ada Lovelace', $this->finder()->find('player', $ada)?->get('name'));

        // A UUID, or a number no record has: no such page.
        $this->client->request('GET', '/o/player/0190a5e2-4c8b-7d3e-9f10-2b3c4d5e6f70/edit');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/o/player/999999/edit');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/o/player/lookup', ['filter' => ['team' => $red]]);
        self::assertSame([['value' => $ada, 'text' => 'Ada Lovelace']], $this->results());
        $this->client->request('GET', '/o/player/lookup', ['filter' => ['team' => '0190a5e2-4c8b-7d3e-9f10-2b3c4d5e6f70']]);
        self::assertSame([], $this->results());

        $crawler = $this->client->request('GET', \sprintf('/o/player/%s/edit', $ada));
        $this->client->submit($crawler->selectButton('Delete')->form());
        self::assertNull($this->finder()->find('player', $ada));
    }

    public function testTheRowsOfAJunctionAreCheckedByTheirNumbers(): void
    {
        $manager = $this->manager();
        $manager->createObject('person', 'Person', 'People', keyType: KeyType::Integer);
        $manager->createField('person', 'name', 'text', 'Name', required: true);
        $manager->createObject('tag', 'Tag', 'Tags', keyType: KeyType::Integer);
        $manager->createField('tag', 'name', 'text', 'Name', required: true);
        $manager->createObject('person_tag', 'Person tag', 'Person tags', keyType: KeyType::Integer);
        $manager->createField('person_tag', 'person', 'relation', 'Person', required: true, settings: ['target' => 'person', 'onDelete' => 'cascade']);
        $manager->createField('person_tag', 'tag', 'relation', 'Tag', required: true, settings: ['target' => 'tag', 'onDelete' => 'cascade']);
        $manager->addUniqueTogether('person_tag', ['person', 'tag']);
        $manager->createField('person', 'tags', 'many_to_many', 'Tags', settings: ['target' => 'tag', 'through' => 'person_tag', 'throughSource' => 'person', 'throughTarget' => 'tag']);
        $vip = $this->create('tag', ['name' => 'VIP']);
        $press = $this->create('tag', ['name' => 'Press']);

        $crawler = $this->client->request('GET', '/o/person/new');
        $row = static fn (string $tag): array => ['id' => '', 'values' => ['tag' => $tag]];
        // The same target twice: told on the row, before anything is written.
        $this->post('/o/person/new', $crawler, ['name' => 'Ada', 'tags' => [$row($vip), $row($vip)]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.gv-collection__row:nth-child(2)', 'This record is already linked.');
        self::assertSame([], $this->finder()->latest('person'));

        $this->post('/o/person/new', $crawler, ['name' => 'Ada', 'tags' => [$row($vip), $row($press)]]);
        self::assertResponseRedirects();
        self::assertEqualsCanonicalizing([$vip, $press], $this->finder()->latest('person')[0]->get('tags'));
    }

    public function testAWorkflowRunsOnARecordWithAnIntegerKey(): void
    {
        $this->teams();
        $red = $this->create('team', ['name' => 'Red']);
        $workflow = $this->activate("trigger: { on: manual, object: team, label: Copy }\nsteps: [{ key: copy, type: create_record, object: team, values: { name: 'Copy of {{ \$trigger.record.name }}' } }]");

        // From the record's page: the menu's form posts to /w/<workflow>/run/<number>.
        $crawler = $this->client->request('GET', \sprintf('/o/team/%s/edit', $red));
        $form = $crawler->filter('form[id^="md-action-"]')->form();
        self::assertStringEndsWith(\sprintf('/w/%s/run/%s', $workflow, $red), $form->getUri());
        $this->client->submit($form);
        self::assertResponseRedirects(\sprintf('/o/team/%s/edit', $red), 303);

        self::assertSame(['Copy of Red', 'Red'], array_map(static fn (RecordOutput $team): mixed => $team->get('name'), $this->finder()->latest('team')));
        // The run names its record by its key, as text.
        self::assertSame($red, $this->connection()->fetchOne('SELECT record_id FROM core.workflow_run WHERE workflow_id = ?', [$workflow]));
    }

    /**
     * team (integer key); player (integer key): team → team.
     */
    private function teams(): void
    {
        $manager = $this->manager();
        $manager->createObject('team', 'Team', 'Teams', keyType: KeyType::Integer);
        $manager->createField('team', 'name', 'text', 'Name', required: true);
        $manager->createObject('player', 'Player', 'Players', keyType: KeyType::Integer);
        $manager->createField('player', 'name', 'text', 'Name', required: true);
        $manager->createField('player', 'team', 'relation', 'Team', settings: ['target' => 'team']);
    }

    /**
     * @return string the id of the active workflow
     */
    private function activate(string $yaml): string
    {
        // As the super admin, outside a request.
        $user = self::getContainer()->get(UserProvider::class)->loadUserByIdentifier('admin@example.com');
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $manager = self::getContainer()->get(WorkflowManager::class);
        $workflow = $manager->create('Copy', null, $yaml);
        $manager->activate($workflow);

        return $workflow->getId()->toRfc4122();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function create(string $object, array $values): string
    {
        return self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput($object, $values))->id;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function post(string $uri, Crawler $crawler, array $changes): void
    {
        $values = $crawler->selectButton('Save')->form()->getPhpValues();
        $current = \is_array($values['record'] ?? null) ? $values['record'] : [];
        $values['record'] = $changes + $current;
        $this->client->request('POST', $uri, $values);
    }

    /**
     * @return list<mixed>
     */
    private function results(): array
    {
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($json) && \is_array($json['results'] ?? null) ? array_values($json['results']) : [];
    }

    private function registry(): MetadataRegistryInterface
    {
        return self::getContainer()->get(MetadataRegistryInterface::class);
    }

    private function manager(): MetadataManagerInterface
    {
        return self::getContainer()->get(MetadataManagerInterface::class);
    }

    private function finder(): RecordFinderInterface
    {
        return self::getContainer()->get(RecordFinderInterface::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
