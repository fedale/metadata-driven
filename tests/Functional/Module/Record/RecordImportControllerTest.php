<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\Access\Contract\Permissions;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Tests\Support\CreatesPetObject;
use App\Tests\Support\RemovesDirectories;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;

/**
 * The CSV import wizard: upload, columns, check, import.
 */
final class RecordImportControllerTest extends WebTestCase
{
    use CreatesPetObject;
    use RemovesDirectories;
    use SignsIn;

    private KernelBrowser $client;
    private string $directory;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::createPetObject(self::getContainer());
        $this->directory = sys_get_temp_dir().'/md-wizard-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        // The drafts left by another test would count as this one's.
        array_map(unlink(...), $this->drafts());
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
        parent::tearDown();
    }

    public function testImportsAFileInThreeSteps(): void
    {
        self::signInAsSuperAdmin($this->client);
        $crawler = $this->client->request('GET', '/o/pet');
        self::assertSame('/o/pet/import', $crawler->selectLink('Import')->attr('href'));

        // Semicolons, as Excel writes them in Italian; headers by label, by name, and unknown.
        $crawler = $this->upload("\xEF\xBB\xBFName;species;Chip code;Notes\nRex;dog;A1;good boy\nTom;cat;B2;\n");
        self::assertSelectorTextContains('.md-steps__item--current', 'Columns');
        self::assertSelectorTextContains('main', 'pets.csv, 2 rows.');
        self::assertSame(['name', 'species', 'chip_code', ''], $this->mapping($crawler));
        // First values, to tell the columns apart.
        self::assertSelectorTextContains('.md-import-columns', 'good boy');

        $this->client->submitForm('Continue', ['columns[key]' => 'chip_code']);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', '2 record(s) will be created, 0 updated.');
        self::assertSame(['Line', 'Name', 'Species', 'Chip code'], $crawler->filter('thead th')->each(static fn (Crawler $th): string => $th->text()));
        self::assertSame([], self::getContainer()->get(RecordFinderInterface::class)->latest('pet'));

        $this->client->submitForm('Import');
        self::assertResponseRedirects('/o/pet');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', '2 record(s) created, 0 updated.');
        $names = array_map(static fn ($record): mixed => $record->values['name'], self::getContainer()->get(RecordFinderInterface::class)->latest('pet'));
        sort($names);
        self::assertSame(['Rex', 'Tom'], $names);
        // The draft is gone with its file.
        self::assertSame([], $this->drafts());

        // Again, with the key: the same rows update the records.
        $this->upload("chip_code,name\nA1,Rex the Second\n");
        $this->client->submitForm('Continue', ['columns[key]' => 'chip_code']);
        $this->client->followRedirect();
        $this->client->submitForm('Import');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', '0 record(s) created, 1 updated.');
    }

    public function testTheCheckListsTheRowsRefusedAndImportsNothing(): void
    {
        self::signInAsSuperAdmin($this->client);
        $this->upload("name,weight\nRex,4.5\n,3\nAn awfully long name for a pet,2\n");
        $this->client->submitForm('Continue');
        $this->client->followRedirect();

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.md-flash--error', 'Nothing would be imported: 2 problems.');
        self::assertSelectorTextContains('.md-import__reasons', 'Line 3: name: This value is required.');
        self::assertSelectorNotExists('form[name="run"]');
        self::assertSelectorExists('a:contains("Back to the columns")');
    }

    public function testRefusesFilesItCannotRead(): void
    {
        self::signInAsSuperAdmin($this->client);

        $this->upload("name\nCitt\xE0\n");
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main form', 'Line 2 is not UTF-8');

        $this->upload("name\n");
        self::assertSelectorTextContains('main form', 'The file has a header but no rows.');

        $this->upload("name,name\nRex,Tom\n");
        self::assertSelectorTextContains('main form', 'Column 2 repeats the header "name"');

        // Nothing waits in a draft.
        self::assertSame([], $this->drafts());
    }

    public function testRefusesAFieldTwiceAndAKeyNoColumnFills(): void
    {
        self::signInAsSuperAdmin($this->client);
        $this->upload("name,nickname,age\nRex,Rexy,3\n");

        $this->client->submitForm('Continue', ['columns[c1]' => 'name', 'columns[key]' => 'chip_code']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.md-import-columns', 'The column "name" fills this field already.');
        self::assertSelectorTextContains('main form', 'The key must be one of the fields the columns fill.');
    }

    public function testFileFieldsAreNotOffered(): void
    {
        self::getContainer()->get(MetadataManagerInterface::class)->createField('pet', 'photo', 'file', 'Photo');
        self::signInAsSuperAdmin($this->client);
        $crawler = $this->upload("name,photo\nRex,rex.png\n");

        self::assertSame(['name', ''], $this->mapping($crawler));
        self::assertCount(0, $crawler->filter('select[name="columns[c1]"] option[value="photo"]'));
    }

    public function testMatchesARelationByAUniqueFieldOfItsTarget(): void
    {
        $metadata = self::getContainer()->get(MetadataManagerInterface::class);
        $metadata->createObject('owner', 'Owner', 'Owners');
        $metadata->createField('owner', 'email', 'email', 'Email', required: true, unique: true);
        $metadata->createField('pet', 'owner', 'relation', 'Owner', settings: ['target' => 'owner']);
        self::signInAsSuperAdmin($this->client);
        $this->upload("email\nada@example.com\n", 'owner');
        $this->client->submitForm('Continue');
        $this->client->followRedirect();
        $this->client->submitForm('Import');

        $this->upload("name,owner\nRex,ada@example.com\n");
        $this->client->submitForm('Continue', ['columns[m_owner]' => 'email']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', '1 record(s) will be created');
        $this->client->submitForm('Import');

        $finder = self::getContainer()->get(RecordFinderInterface::class);
        self::assertSame($finder->latest('owner')[0]->id, $finder->latest('pet')[0]->values['owner']);
    }

    public function testOffersARelationOnlyToWhoMayReadItsRecords(): void
    {
        $metadata = self::getContainer()->get(MetadataManagerInterface::class);
        $metadata->createObject('owner', 'Owner', 'Owners');
        $metadata->createField('owner', 'email', 'email', 'Email', required: true, unique: true);
        $metadata->createField('pet', 'owner', 'relation', 'Owner', settings: ['target' => 'owner']);
        // May create pets, not read owners: the form's lookup would refuse them too.
        self::signIn($this->client, 'clerk@example.com', ['record.pet.read', 'record.pet.create']);

        $crawler = $this->upload("name,owner\nRex,ada@example.com\n");

        self::assertSame(['name', ''], $this->mapping($crawler));
        self::assertCount(0, $crawler->filter('select[name="columns[c1]"] option[value="owner"]'));
        self::assertSelectorNotExists('select[name="columns[m_owner]"]');
    }

    public function testNeedsTheCreatePermissionAndTheUpdateOneForAKey(): void
    {
        self::signIn($this->client, 'reader@example.com', ['record.pet.read']);
        $this->client->request('GET', '/o/pet/import');
        self::assertResponseStatusCodeSame(403);

        self::signIn($this->client, 'clerk@example.com', ['record.pet.read', 'record.pet.create']);
        $this->upload("name,chip_code\nRex,A1\n");
        $this->client->submitForm('Continue', ['columns[key]' => 'chip_code']);
        self::assertSelectorTextContains('main form', 'Updating records needs the permission to change them');

        // Without a key the clerk imports.
        $this->client->submitForm('Continue', ['columns[key]' => '']);
        $this->client->followRedirect();
        $this->client->submitForm('Import');
        self::assertResponseRedirects('/o/pet');
    }

    public function testADraftBelongsToItsObjectAndItsSession(): void
    {
        self::getContainer()->get(MetadataManagerInterface::class)->createObject('owner', 'Owner', 'Owners');
        self::signInAsSuperAdmin($this->client);
        $this->upload("name\nRex\n");
        $columns = (string) $this->client->getRequest()->getPathInfo();
        $draft = basename($columns);

        $this->client->request('GET', '/o/owner/import/'.$draft);
        self::assertResponseStatusCodeSame(404);

        // Another session: the draft is unknown there.
        $this->client->getCookieJar()->clear();
        self::signIn($this->client, 'other@example.com', [Permissions::SUPER_ADMIN_ROLE]);
        $this->client->request('GET', $columns);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCancellingDeletesTheDraft(): void
    {
        self::signInAsSuperAdmin($this->client);
        $this->upload("name\nRex\n");
        $columns = (string) $this->client->getRequest()->getPathInfo();

        $this->client->submitForm('Cancel the import');
        self::assertResponseRedirects('/o/pet');
        $this->client->request('GET', $columns);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Uploads a CSV file at the first step; follows to the columns when it is taken.
     */
    private function upload(string $csv, string $object = 'pet'): Crawler
    {
        $path = $this->directory.'/'.$object.'s.csv';
        file_put_contents($path, $csv);
        $crawler = $this->client->request('GET', '/o/'.$object.'/import');
        $form = $crawler->selectButton('Continue')->form();
        $file = $form['import[file]'];
        self::assertInstanceOf(FileFormField::class, $file);
        $file->upload($path);
        $crawler = $this->client->submit($form);

        return $this->client->getResponse()->isRedirection() ? $this->client->followRedirect() : $crawler;
    }

    /**
     * @return list<string> the field chosen for each column; "" when ignored
     */
    private function mapping(Crawler $crawler): array
    {
        return $crawler->filter('.md-import-columns select')->each(static function (Crawler $select): string {
            $selected = $select->filter('option[selected]');

            return 0 === $selected->count() ? '' : (string) $selected->attr('value');
        });
    }

    /**
     * @return list<string> the files of the drafts waiting
     */
    private function drafts(): array
    {
        return glob(self::getContainer()->getParameter('app.import.drafts_dir').'/*.csv') ?: [];
    }
}
