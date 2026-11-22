<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Admin;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
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

    public function testAnAdministratorTriesThenAdoptsATable(): void
    {
        self::signInAsSuperAdmin($this->client);
        self::getContainer()->get(Connection::class)->executeStatement("INSERT INTO data.project (title) VALUES ('Alpha')");

        $crawler = $this->client->request('GET', '/admin/tables/project');
        $crawler = $this->client->click($crawler->selectLink('Make it an object…')->link());
        self::assertResponseIsSuccessful();
        self::assertSame('project', $crawler->filter('input[name="adopt[name]"]')->attr('value'));
        self::assertSame('title', $crawler->filter('input[name="adopt[columns][c0][field]"]')->attr('value'));
        self::assertSame('text', $crawler->filter('select[name="adopt[columns][c0][type]"] option[selected]')->attr('value'));

        // Tried: the changes shown, nothing changed.
        $crawler = $this->client->submit($crawler->selectButton('Try it')->form(['adopt[labelPlural]' => 'Projects', 'adopt[columns][c0][label]' => 'Project title']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.md-flash--success', 'adopting it makes the object "project"');
        self::assertSelectorTextContains('.md-sql', 'ALTER TABLE "data"."project" RENAME TO "c_project"');
        self::assertFalse(self::getContainer()->get(MetadataRegistryInterface::class)->hasObject('project'));

        // A name taken: told on the page.
        self::getContainer()->get(MetadataManagerInterface::class)->createObject('taken', 'Taken', 'Takens');
        $this->client->submit($crawler->selectButton('Adopt')->form(['adopt[name]' => 'taken']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.md-flash--error', 'An object is named "taken" already: choose another name.');

        $crawler = $this->client->getCrawler();
        $this->client->submit($crawler->selectButton('Adopt')->form(['adopt[name]' => 'project', 'adopt[labelPlural]' => 'Projects', 'adopt[columns][c0][label]' => 'Project title']));
        self::assertResponseRedirects('/admin/objects/project', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'Table "project" is the object "project" now.');
        $object = self::getContainer()->get(MetadataRegistryInterface::class)->getObject('project');
        self::assertSame(['Projects', 'Project title'], [$object->labelPlural, $object->field('title')->label]);

        // Its records, by their numbers.
        $this->client->request('GET', '/o/project/1/edit');
        self::assertResponseIsSuccessful();
        self::assertSame('Alpha', $this->client->getCrawler()->filter('input[name="record[title]"]')->attr('value'));
    }

    public function testTheConsoleAdoptsATable(): void
    {
        $adopt = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('metadata:table:adopt'));

        self::assertSame(Command::SUCCESS, $adopt->execute(['table' => 'project', '--dry-run' => true]));
        $display = static fn (): string => (string) preg_replace('/\s+/', ' ', $adopt->getDisplay());
        self::assertStringContainsString('Dry run: object "project" would be made of table "project". Nothing was changed.', $display());
        self::assertSame(Command::FAILURE, $adopt->execute(['table' => 'loose']));
        self::assertStringContainsString('It has no primary key: give it one first.', $display());

        self::assertSame(Command::SUCCESS, $adopt->execute(['table' => 'project', '--name' => 'plan', '--plural' => 'Plans']));
        self::assertStringContainsString('Table "project" is object "plan" now.', $display());
        self::assertSame('Plans', self::getContainer()->get(MetadataRegistryInterface::class)->getObject('plan')->labelPlural);
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
