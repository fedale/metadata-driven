<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\Access\Contract\Permissions;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\CreatesPetObject;
use App\Tests\Support\RemovesDirectories;
use App\Tests\Support\SignsIn;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;

/**
 * The wizard's files above app.import.max_rows (3 in the tests), checked and imported by the
 * worker in batches of app.import.batch_size (2). The queue is the request's in the tests: a
 * job is done when the redirect to its page comes.
 */
final class RecordImportJobControllerTest extends WebTestCase
{
    use CreatesPetObject;
    use RemovesDirectories;
    use SignsIn;

    private const string FIVE_PETS = "name,chip_code\nRex,A1\nTom,B2\nFido,C3\nLuna,D4\nMax,E5\n";

    private KernelBrowser $client;
    private string $directory;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::createPetObject(self::getContainer());
        $this->directory = sys_get_temp_dir().'/md-jobs-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        // The files left by another test would count as this one's.
        array_map(unlink(...), $this->files());
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
        parent::tearDown();
    }

    public function testChecksThenImportsALargeFileInTheBackground(): void
    {
        self::signInAsSuperAdmin($this->client);
        $this->upload(self::FIVE_PETS);
        self::assertSelectorTextContains('main', 'pets.csv, 5 rows.');

        $job = $this->continueToJob(['columns[key]' => 'chip_code']);
        self::assertMatchesRegularExpression('~^/o/pet/imports/[0-9a-f-]{36}$~', $job);
        self::assertSelectorTextContains('.md-import-job__status', 'Checked');
        self::assertSelectorTextContains('.md-flash--success', '5 record(s) will be created, 0 updated.');
        self::assertSame([], self::getContainer()->get(RecordFinderInterface::class)->latest('pet'));
        self::assertSame(['admin@example.com is checked' => 1], $this->notifications('pets.csv'));

        $this->client->submitForm('Import');
        self::assertResponseRedirects($job);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-import-job__status', 'Imported');
        self::assertSelectorTextContains('.md-flash--success', '5 record(s) created, 0 updated.');
        self::assertCount(5, self::getContainer()->get(RecordFinderInterface::class)->latest('pet', 10));
        self::assertSame(['admin@example.com is checked' => 1, 'admin@example.com is imported' => 1], $this->notifications('pets.csv'));
        // Its file is gone with the import; nothing waits in the drafts either.
        self::assertSame([], $this->files());

        $crawler = $this->client->request('GET', '/o/pet/imports');
        self::assertSame(['Imported'], $crawler->filter('tbody .md-import-job__status')->each(static fn (Crawler $cell): string => $cell->text()));
    }

    public function testARefusedCheckGoesBackToTheColumns(): void
    {
        self::signInAsSuperAdmin($this->client);
        $this->upload("name,chip_code\nRex,A1\n,B2\nTom,C3\nAn awfully long name for a pet,D4\n");
        $this->continueToJob(['columns[key]' => 'chip_code']);

        self::assertSelectorTextContains('.md-import-job__status', 'Refused');
        self::assertSelectorTextContains('.md-flash--error', 'Nothing would be imported: 2 problems.');
        self::assertSelectorTextContains('.md-import__reasons', 'Line 3: name: This value is required.');
        self::assertSelectorNotExists('form[name="run"]');

        $this->client->submitForm('Back to the columns');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'pets.csv, 4 rows.');
        self::assertSame('chip_code', $crawler->filter('select[name="columns[key]"] option[selected]')->attr('value'));
        // The job gave its file back: a draft again.
        self::assertCount(1, $this->files());
        self::assertSame([], $this->client->request('GET', '/o/pet/imports')->filter('tbody tr')->each(static fn (Crawler $row): string => $row->text()));
    }

    public function testAnImportStopsAtABatchRefusedSinceItsCheck(): void
    {
        self::signInAsSuperAdmin($this->client);
        $this->upload(self::FIVE_PETS);
        $this->continueToJob();
        self::assertSelectorTextContains('.md-import-job__status', 'Checked');

        // Meanwhile, a record takes the chip code of line 4, in the second batch.
        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('pet', ['name' => 'Fido the First', 'chip_code' => 'C3']));
        $this->client->submitForm('Import');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.md-import-job__status', 'Stopped');
        self::assertSelectorTextContains('main', '2 record(s) created and 0 updated before it stopped: they stay.');
        self::assertSelectorTextContains('main', 'Nothing was written from line 4 on.');
        self::assertSelectorTextContains('.md-import__reasons', 'Line 4: chip_code:');
        // The first batch, and the record of before.
        self::assertCount(3, self::getContainer()->get(RecordFinderInterface::class)->latest('pet', 10));
    }

    public function testAnImportIsItsCreatorsOnly(): void
    {
        self::signInAsSuperAdmin($this->client);
        $this->upload(self::FIVE_PETS);
        $job = $this->continueToJob();

        $this->client->getCookieJar()->clear();
        self::signIn($this->client, 'other@example.com', [Permissions::SUPER_ADMIN_ROLE]);
        $this->client->request('GET', $job);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $job.'/run');
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->client->request('GET', '/o/pet/imports')->filter('tbody tr')->each(static fn (Crawler $row): string => $row->text()));
    }

    public function testRefusesAFileAboveTheBackgroundsLimit(): void
    {
        self::signInAsSuperAdmin($this->client);
        $this->upload("name\n".str_repeat("Rex\n", 9));

        self::assertSelectorTextContains('main form', 'The file has 9 rows, the wizard imports up to 8');
    }

    /**
     * Submits the columns: the check step sends the file to the background, and the job's page
     * comes once it is checked (the queue is the request's).
     *
     * @param array<string, string> $values
     *
     * @return string the job's page
     */
    private function continueToJob(array $values = []): string
    {
        $this->client->submitForm('Continue', $values);
        $this->client->followRedirect();
        $job = (string) $this->client->getResponse()->headers->get('Location');
        $this->client->followRedirect();

        return $job;
    }

    /**
     * Uploads a CSV file at the first step; follows to the columns when it is taken.
     */
    private function upload(string $csv): Crawler
    {
        $path = $this->directory.'/pets.csv';
        file_put_contents($path, $csv);
        $crawler = $this->client->request('GET', '/o/pet/import');
        $form = $crawler->selectButton('Continue')->form();
        $file = $form['import[file]'];
        self::assertInstanceOf(FileFormField::class, $file);
        $file->upload($path);
        $crawler = $this->client->submit($form);

        return $this->client->getResponse()->isRedirection() ? $this->client->followRedirect() : $crawler;
    }

    /**
     * @return array<string, int> "<recipient> <title without the file's name>" => how many
     */
    private function notifications(string $file): array
    {
        $rows = self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT recipient, title FROM core.notification ORDER BY created_at, title');
        $counts = [];
        foreach ($rows as ['recipient' => $recipient, 'title' => $title]) {
            self::assertIsString($recipient);
            self::assertIsString($title);
            $key = $recipient.' '.trim(str_replace($file, '', $title));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return list<string> the files of the drafts and of the jobs
     */
    private function files(): array
    {
        $directory = self::getContainer()->getParameter('app.import.drafts_dir');

        return [...glob($directory.'/*.csv') ?: [], ...glob($directory.'/jobs/*.csv') ?: []];
    }
}
