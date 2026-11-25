<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The country, language and currency types through HTTP: gridview's controls listing the
 * names in the page's language, the preferred codes first; the grid naming the codes and
 * filtering by name; the export.
 */
final class CodeFieldsTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);
        $container = self::getContainer();
        ($container->get(CreateObjectHandler::class))(new CreateObjectCommand('supplier', 'Supplier', 'Suppliers'));
        ($container->get(CreateFieldHandler::class))(new CreateFieldCommand('supplier', 'name', 'text', 'Name', isRequired: true));
        ($container->get(CreateFieldHandler::class))(new CreateFieldCommand('supplier', 'country', 'country', 'Country', settings: ['preferred' => ['IT', 'FR'], 'default' => 'IT']));
        ($container->get(CreateFieldHandler::class))(new CreateFieldCommand('supplier', 'language', 'language', 'Language'));
        ($container->get(CreateFieldHandler::class))(new CreateFieldCommand('supplier', 'currency', 'currency', 'Currency', isRequired: true));
    }

    public function testCreatesARecordWithItsCodes(): void
    {
        $crawler = $this->client->request('GET', '/o/supplier/new');
        $country = $crawler->filter('select[name="record[country]"]');
        // The preferred codes first, the default chosen.
        self::assertSame(['IT', 'FR'], \array_slice(array_values(array_filter($country->filter('option')->extract(['value']))), 0, 2));
        self::assertSame('IT', $country->filter('option[selected]')->attr('value'));
        self::assertCount(1, $crawler->filter('select[name="record[language]"] option[value="fur"]'));

        $form = $crawler->selectButton('Save')->form(['record[name]' => 'Rossi', 'record[language]' => 'it', 'record[currency]' => 'EUR']);
        $this->client->submit($form);
        self::assertResponseRedirects('/o/supplier', 303);

        $supplier = self::getContainer()->get(RecordFinderInterface::class)->latest('supplier')[0];
        self::assertSame(['IT', 'it', 'EUR'], [$supplier->get('country'), $supplier->get('language'), $supplier->get('currency')]);
    }

    public function testRefusesAnUnknownCode(): void
    {
        $this->expectException(RecordValidationFailed::class);

        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('supplier', ['name' => 'Rossi', 'currency' => 'XYZ']));
    }

    public function testTheGridNamesTheCodesInTheUsersLanguageAndFiltersByName(): void
    {
        $records = self::getContainer()->get(RecordManagerInterface::class);
        $records->create(new RecordInput('supplier', ['name' => 'Dupont', 'country' => 'fr', 'language' => 'fr', 'currency' => 'eur']));
        $records->create(new RecordInput('supplier', ['name' => 'Rossi', 'country' => 'IT', 'language' => 'it', 'currency' => 'EUR']));
        $records->create(new RecordInput('supplier', ['name' => 'Smith', 'country' => 'GB', 'currency' => 'GBP']));

        $italian = ['HTTP_ACCEPT_LANGUAGE' => 'it-IT,it;q=0.9'];
        $crawler = $this->client->request('GET', '/o/supplier', ['sort' => 'name'], server: $italian);
        self::assertResponseIsSuccessful();
        self::assertSame(['🇫🇷 Francia', '🇮🇹 Italia', '🇬🇧 Regno Unito'], $this->cells('country'));
        self::assertSame(['francese', 'italiano', ''], $this->cells('language'));
        self::assertSame(['euro (EUR)', 'euro (EUR)', 'sterlina britannica (GBP)'], $this->cells('currency'));
        self::assertContains('Italia', $this->filterChoices($crawler->filter('select[name="fedaleForm[country][]"]')));

        $this->client->request('GET', '/o/supplier', ['sort' => 'name', 'fedaleForm' => ['country' => ['IT', 'GB']]], server: $italian);
        self::assertSame(['Rossi', 'Smith'], $this->cells('name'));

        $this->client->request('GET', '/o/supplier/export', ['format' => 'csv', 'sort' => 'name', 'cols' => 'name,currency'], server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertSame(
            ['Name,Currency', 'Dupont,"Euro (EUR)"', 'Rossi,"Euro (EUR)"', 'Smith,"British Pound (GBP)"'],
            array_map(trim(...), explode("\n", trim((string) $this->client->getInternalResponse()->getContent(), "\u{FEFF}\n"))),
        );
    }

    /**
     * @return list<string>
     */
    private function cells(string $column): array
    {
        return $this->client->getCrawler()
            ->filter(\sprintf('#gv-tbody-record_supplier td[data-col-key="%s"]', $column))
            ->each(static fn ($cell): string => trim($cell->text()));
    }

    /**
     * The labels a relation filter offers: its controller draws them from a JSON attribute.
     *
     * @return list<mixed>
     */
    private function filterChoices(Crawler $select): array
    {
        $choices = json_decode((string) $select->attr('data-gridview-relation-filter-choices-value'), true);
        self::assertIsArray($choices);

        return array_column($choices, 'l');
    }
}
