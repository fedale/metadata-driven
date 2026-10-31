<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\CreatesAccountObject;
use App\Tests\Support\CreatesContactObject;
use App\Tests\Support\CreatesPetObject;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Drives the dynamic record forms through HTTP, as a browser would.
 */
final class RecordControllerTest extends WebTestCase
{
    use SignsIn;
    use CreatesAccountObject;
    use CreatesContactObject;
    use CreatesPetObject;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Stateless CSRF protection accepts same-origin requests.
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);
        self::createPetObject(self::getContainer());
    }

    public function testHomeListsObjects(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.md-link-list a', 'Pets');
    }

    public function testFormIsBuiltFromMetadata(): void
    {
        $crawler = $this->client->request('GET', '/o/pet/new');

        self::assertResponseIsSuccessful();
        self::assertSame('20', $crawler->filter('input[name="record[name]"]')->attr('maxlength'));
        self::assertSame('required', $crawler->filter('input[name="record[name]"]')->attr('required'));
        // The label of a required field is marked (an asterisk, explained under the form).
        self::assertStringContainsString('required', (string) $crawler->filter('label[for="record_name"]')->attr('class'));
        self::assertStringNotContainsString('required', (string) $crawler->filter('label[for="record_weight"]')->attr('class'));
        self::assertSelectorTextContains('.gv-required-note', 'Required field');
        self::assertCount(3, $crawler->filter('select[name="record[species]"] option'));
        self::assertSame('number', $crawler->filter('input[name="record[weight]"]')->attr('type'));
        self::assertSame('0.01', $crawler->filter('input[name="record[weight]"]')->attr('step'));
        self::assertSame('checkbox', $crawler->filter('input[name="record[vaccinated]"]')->attr('type'));
        self::assertSame('date', $crawler->filter('input[name="record[born_on]"]')->attr('type'));
        self::assertSame('email', $crawler->filter('input[name="record[owner_email]"]')->attr('type'));
    }

    public function testFormFollowsTheDefaultViewOrTheNamedOne(): void
    {
        $manager = self::getContainer()->get(MetadataManagerInterface::class);
        $manager->saveView('pet', ViewKind::Form, 'main', '<section class="identity"><h2>Identity</h2>{species}{name}</section>');
        $manager->saveView('pet', ViewKind::Form, 'health', '<section class="health">{vaccinated}</section>');

        $crawler = $this->client->request('GET', '/o/pet/new');
        self::assertResponseIsSuccessful();
        $layout = $crawler->filter('.md-view-layout section.identity');
        self::assertSame('Identity', $layout->filter('h2')->text());
        // The layout decides the order; the fields it leaves out follow it.
        self::assertSame(['record[species]', 'record[name]'], $layout->filter('select, input')->each(static fn ($input): ?string => $input->attr('name')));
        self::assertCount(0, $crawler->filter('.md-view-layout input[name="record[weight]"]'));
        self::assertCount(1, $crawler->filter('form.md-sheet input[name="record[weight]"]'));

        $this->client->submitForm('Save', ['record[name]' => 'Rex', 'record[species]' => 'dog']);
        self::assertResponseRedirects('/o/pet', 303);

        $crawler = $this->client->request('GET', '/o/pet/new?view=health');
        self::assertCount(1, $crawler->filter('.md-view-layout section.health input[name="record[vaccinated]"]'));
        self::assertCount(0, $crawler->filter('.md-view-layout input[name="record[name]"]'));

        $this->client->request('GET', '/o/pet/new?view=missing');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRicherFieldTypesInTheForm(): void
    {
        self::createAccountObject(self::getContainer());

        $crawler = $this->client->request('GET', '/o/account/new');
        self::assertResponseIsSuccessful();
        // A switch checked by default, buttons side by side, the password asked twice, never prefilled.
        self::assertSame('checked', $crawler->filter('input.gv-switch[role="switch"][name="record[active]"]')->attr('checked'));
        self::assertCount(3, $crawler->filter('.gv-segmented input[type="radio"][name="record[gender]"]'));
        self::assertCount(1, $crawler->filter('input[type="password"][name="record[password][first]"]'));
        self::assertCount(1, $crawler->filter('input[type="password"][name="record[password][second]"]'));
        // The strength meter watches the password typed first, not its confirmation.
        // So does the show/hide eye.
        self::assertSame('gridview-password-strength gridview-password-reveal', $crawler->filter('input[name="record[password][first]"]')->attr('data-controller'));
        self::assertSame('8', $crawler->filter('input[name="record[password][first]"]')->attr('data-gridview-password-strength-min-length-value'));
        self::assertNull($crawler->filter('input[name="record[password][second]"]')->attr('data-controller'));
        self::assertCount(1, $crawler->filter('input[type="url"][name="record[website]"]'));
        self::assertCount(1, $crawler->filter('input[type="tel"][name="record[phone]"]'));

        $values = ['record[username]' => 'mario', 'record[password][first]' => 'secret123', 'record[password][second]' => 'secret124', 'record[gender]' => 'm'];
        $this->client->submitForm('Save', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('.gv-form-error', 'The two passwords do not match.');

        $this->client->submitForm('Save', ['record[password][second]' => 'secret123'] + $values);
        self::assertResponseRedirects('/o/account', 303);

        // The grid has no password column; the edit form leaves the password empty and optional.
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextNotContains('table', 'Password');
        $id = self::getContainer()->get(RecordFinderInterface::class)->latest('account', 1)[0]->id;
        $crawler = $this->client->request('GET', '/o/account/'.$id.'/edit');
        $password = $crawler->filter('input[name="record[password][first]"]');
        self::assertSame('', (string) $password->attr('value'));
        self::assertNull($password->attr('required'));
        self::assertSelectorTextContains('main form', 'Leave empty to keep the current password.');
        self::assertSame('checked', $crawler->filter('input[name="record[gender]"][value="m"]')->attr('checked'));
    }

    public function testCreatesARecord(): void
    {
        $this->client->request('GET', '/o/pet/new');
        $this->client->submitForm('Save', [
            'record[name]' => 'Rex',
            'record[species]' => 'dog',
            'record[weight]' => '12.5',
            'record[vaccinated]' => true,
            'record[born_on]' => '2023-04-01',
        ]);

        self::assertResponseRedirects('/o/pet', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'Pet created.');
        self::assertSelectorTextSame('#gv-tbody-record_pet td[data-col-key="name"]', 'Rex');
        self::assertSelectorTextSame('#gv-tbody-record_pet td[data-col-key="species"]', 'Dog');
        self::assertSelectorTextSame('#gv-tbody-record_pet td[data-col-key="weight"]', '12.50');
        self::assertSelectorTextSame('#gv-tbody-record_pet td[data-col-key="vaccinated"]', '✓');
    }

    public function testGridFiltersSearchesAndSorts(): void
    {
        foreach ([['Rex', 'dog', 3], ['Fido', 'dog', 7], ['Micia', 'cat', 4]] as [$name, $species, $age]) {
            $this->records()->create(new RecordInput('pet', ['name' => $name, 'species' => $species, 'age' => $age]));
        }

        $this->client->request('GET', '/o/pet', ['sort' => '-name']);
        self::assertResponseIsSuccessful();
        self::assertSame(['Rex', 'Micia', 'Fido'], $this->gridNames());

        // Column filters use the same syntax as on any gridview grid.
        $this->client->request('GET', '/o/pet', ['fedaleForm' => ['species' => 'dog', 'age' => '>=5']]);
        self::assertSame(['Fido'], $this->gridNames());

        $this->client->request('GET', '/o/pet', ['fedaleForm' => ['_q' => 'MIC']]);
        self::assertSame(['Micia'], $this->gridNames());
    }

    public function testTheSearchBoxMatchesWordsAcrossFieldsInAnyOrder(): void
    {
        self::createContacts(self::getContainer());

        $this->client->request('GET', '/o/contact', ['fedaleForm' => ['_q' => 'London John']]);

        self::assertResponseIsSuccessful();
        self::assertSame(['John'], $this->client->getCrawler()
            ->filter('#gv-tbody-record_contact td[data-col-key="first_name"]')
            ->each(static fn ($cell): string => trim($cell->text())));
        self::assertSelectorTextSame('#gv-tbody-record_contact td[data-col-key="city"]', 'London');
    }

    public function testEditLinksPointToTheRecordForm(): void
    {
        $record = $this->records()->create(new RecordInput('pet', ['name' => 'Rex']));

        $crawler = $this->client->request('GET', '/o/pet');

        self::assertSame(\sprintf('/o/pet/%s/edit', $record->id), $crawler->filter('#gv-tbody-record_pet a:contains("Edit")')->attr('href'));
    }

    public function testExportsTheFilteredRowsAsCsv(): void
    {
        $this->records()->create(new RecordInput('pet', ['name' => 'Rex', 'species' => 'dog']));
        $this->records()->create(new RecordInput('pet', ['name' => 'Micia', 'species' => 'cat']));

        $this->client->request('GET', '/o/pet/export', ['format' => 'csv', 'fedaleForm' => ['species' => 'cat'], 'cols' => 'name,species']);

        self::assertResponseIsSuccessful();
        $lines = array_map(trim(...), explode("\n", trim((string) $this->client->getInternalResponse()->getContent(), "\u{FEFF}\n")));
        self::assertSame(['Name,Species', 'Micia,Cat'], $lines);
    }

    public function testEachObjectGridIsAUiSettingsScope(): void
    {
        $crawler = $this->client->request('GET', '/o/pet');
        self::assertSame('record_pet', $crawler->filter('[data-controller="gridview-ui-settings"]')->attr('data-gridview-ui-settings-grid-value'));

        $crawler = $this->client->request('GET', '/gridview/_settings/', ['scope' => 'record_pet']);

        self::assertResponseIsSuccessful();
        self::assertSame('record_pet', $crawler->filter('select option[selected]')->attr('value'));
        self::assertSelectorTextContains('select option[selected]', 'Pets');
    }

    /**
     * @return list<string>
     */
    private function gridNames(): array
    {
        return $this->client->getCrawler()
            ->filter('#gv-tbody-record_pet td[data-col-key="name"]')
            ->each(static fn ($cell): string => trim($cell->text()));
    }

    public function testShowsValidationErrorsFromMetadata(): void
    {
        $this->records()->create(new RecordInput('pet', ['name' => 'Rex', 'chip_code' => 'IT-001']));

        $this->client->request('GET', '/o/pet/new');
        $this->client->submitForm('Save', [
            'record[name]' => '',
            'record[chip_code]' => 'IT-001',
            'record[owner_email]' => 'nope',
            'record[weight]' => '1.234',
        ]);

        self::assertResponseStatusCodeSame(422);
        // Extra decimals are reported, never silently rounded by the form.
        self::assertAnySelectorTextContains('.gv-form-error', 'This value should have at most 2 decimal places.');
        self::assertSelectorTextContains('.gv-form-row:has(#record_name) .gv-form-error', 'This value is required.');
        self::assertAnySelectorTextContains('.gv-form-error', 'This value is already used.');
        self::assertAnySelectorTextContains('.gv-form-error', 'This value is not a valid email address.');
        self::assertSame('nope', $this->client->getCrawler()->filter('input[name="record[owner_email]"]')->attr('value'));
    }

    public function testEditsAndDeletesARecord(): void
    {
        $record = $this->records()->create(new RecordInput('pet', ['name' => 'Rex', 'age' => 3]));

        $this->client->request('GET', \sprintf('/o/pet/%s/edit', $record->id));
        self::assertInputValueSame('record[name]', 'Rex');
        $this->client->submitForm('Save', ['record[name]' => 'Rex II']);
        self::assertResponseRedirects('/o/pet', 303);
        $updated = $this->finder()->find('pet', $record->id);
        self::assertNotNull($updated);
        self::assertSame('Rex II', $updated->get('name'));
        self::assertSame(3, $updated->get('age'));

        $this->client->request('GET', \sprintf('/o/pet/%s/edit', $record->id));
        $this->client->submitForm('Delete');
        self::assertResponseRedirects('/o/pet', 303);
        self::assertNull($this->finder()->find('pet', $record->id));
    }

    public function testUnknownObjectsAndRecordsAre404(): void
    {
        $this->client->request('GET', '/o/ghost');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/o/pet/01a14b6b-f371-729f-8e48-9a43d9f04c84/edit');
        self::assertResponseStatusCodeSame(404);
    }

    private function records(): RecordManagerInterface
    {
        return self::getContainer()->get(RecordManagerInterface::class);
    }

    private function finder(): RecordFinderInterface
    {
        return self::getContainer()->get(RecordFinderInterface::class);
    }
}
