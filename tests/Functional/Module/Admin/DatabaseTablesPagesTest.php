<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Admin;

use App\Tests\Support\SignsIn;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tables loaded into the data schema by hand, as an administrator sees them: greyed under the
 * objects, each with a page of its own, and from the console. Nothing to restart: a table
 * loaded a moment ago is there.
 */
final class DatabaseTablesPagesTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('CREATE TABLE data.project (id serial PRIMARY KEY, title varchar(80) NOT NULL)');
        $connection->executeStatement('CREATE TABLE data.project_activity (id serial PRIMARY KEY, project int REFERENCES data.project (id))');
        $connection->executeStatement('CREATE TABLE data.loose (name text)');
    }

    public function testTheObjectsPageListsThemGreyed(): void
    {
        self::signInAsSuperAdmin($this->client);

        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('.md-table--database-only tbody tr');
        self::assertSame(['loose', 'project', 'project_activity'], $rows->each(static fn ($row): string => $row->filter('td')->first()->text()));
        self::assertSame('No, see why', $rows->eq(0)->filter('td')->last()->text());
        self::assertSame('Yes', $rows->eq(1)->filter('td')->last()->text());
    }

    public function testTheSectionSaysWhereToLoadThemWhenThereAreNone(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        foreach (['project_activity', 'project', 'loose'] as $table) {
            $connection->executeStatement(\sprintf('DROP TABLE data.%s', $table));
        }
        self::signInAsSuperAdmin($this->client);

        $this->client->request('GET', '/admin');
        self::assertSelectorNotExists('.md-table--database-only');
        self::assertSelectorTextContains('body', 'Load them into the schema data');
    }

    public function testATablesPageTellsWhatItHolds(): void
    {
        self::signInAsSuperAdmin($this->client);

        $this->client->request('GET', '/admin/tables/project_activity');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.md-flash--success', 'It can become an object, named "project_activity".');
        self::assertSelectorTextContains('body', 'Integer, numbered by the database');
        self::assertSelectorTextContains('body', 'data.project (id), on delete NO ACTION');

        $this->client->request('GET', '/admin/tables/loose');
        self::assertSelectorTextContains('.md-flash--error', 'It has no primary key: give it one first.');

        $this->client->request('GET', '/admin/tables/c_nothing');
        self::assertResponseStatusCodeSame(404);
    }

    public function testInItalian(): void
    {
        self::signInAsSuperAdmin($this->client);

        $this->client->request('GET', '/admin/tables/loose', server: ['HTTP_ACCEPT_LANGUAGE' => 'it']);
        self::assertSelectorTextContains('.md-flash--error', 'Non ha una chiave primaria: aggiungila prima.');
    }

    public function testOnlyAnAdministratorSeesThem(): void
    {
        self::signIn($this->client, 'anna@example.com');

        $this->client->request('GET', '/admin/tables/project');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheConsoleListsThem(): void
    {
        $console = new Application(self::$kernel ?? self::bootKernel());
        $list = new CommandTester($console->find('metadata:table:list'));

        self::assertSame(Command::SUCCESS, $list->execute([]));
        self::assertMatchesRegularExpression('/project_activity\s+2\s+\S+\s+project_activity\s+integer\s+yes/', $list->getDisplay());
        self::assertMatchesRegularExpression('/loose\s+1\s+\S+\s+loose\s+-\s+no \(--table tells why\)/', $list->getDisplay());

        self::assertSame(Command::SUCCESS, $list->execute(['--table' => 'loose']));
        self::assertStringContainsString('It has no primary key: give it one first.', $list->getDisplay());
        self::assertSame(Command::FAILURE, $list->execute(['--table' => 'nothing']));
    }
}
