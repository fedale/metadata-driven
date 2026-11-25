<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\SignsIn;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The time and color field types through HTTP: gridview's time and color controls in the
 * form, a colour that may be none, the grid's time range filter, the export.
 */
final class TimeAndColorFieldsTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);
        $container = self::getContainer();
        ($container->get(CreateObjectHandler::class))(new CreateObjectCommand('shop', 'Shop', 'Shops'));
        ($container->get(CreateFieldHandler::class))(new CreateFieldCommand('shop', 'name', 'text', 'Name', isRequired: true));
        ($container->get(CreateFieldHandler::class))(new CreateFieldCommand('shop', 'opens', 'time', 'Opens'));
        ($container->get(CreateFieldHandler::class))(new CreateFieldCommand('shop', 'brand', 'color', 'Brand color', isRequired: true));
        ($container->get(CreateFieldHandler::class))(new CreateFieldCommand('shop', 'accent', 'color', 'Accent color'));
    }

    public function testTheColumnsAreATimeAndSevenCharacters(): void
    {
        $types = self::getContainer()->get(Connection::class)->fetchAllKeyValue(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'data' AND table_name = 'c_shop' AND column_name IN ('opens', 'brand')",
        );

        self::assertEqualsCanonicalizing(['opens' => 'time without time zone', 'brand' => 'character varying'], $types);
    }

    public function testCreatesAndEditsARecordWithATimeAndColors(): void
    {
        $crawler = $this->client->request('GET', '/o/shop/new');
        self::assertSame('time', $crawler->filter('input[name="record[opens]"]')->attr('type'));
        // Required: the browser's colour input alone. Optional: with "No color", checked.
        self::assertSame('color', $crawler->filter('input[name="record[brand]"]')->attr('type'));
        self::assertSame('checked', $crawler->filter('input[name="record[accent][none]"]')->attr('checked'));

        $form = $crawler->selectButton('Save')->form([
            'record[name]' => 'Centro',
            'record[opens]' => '09:30',
            'record[brand]' => '#1F6FEB',
            'record[accent][color]' => '#000000',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/o/shop', 303);

        $shop = self::getContainer()->get(RecordFinderInterface::class)->latest('shop')[0];
        $opens = $shop->get('opens');
        self::assertInstanceOf(\DateTimeImmutable::class, $opens);
        self::assertSame('09:30:00', $opens->format('H:i:s'));
        self::assertSame('#1f6feb', $shop->get('brand'));
        self::assertNull($shop->get('accent'));

        $crawler = $this->client->request('GET', \sprintf('/o/shop/%s/edit', $shop->id));
        self::assertSame('09:30', $crawler->filter('input[name="record[opens]"]')->attr('value'));
        $form = $crawler->selectButton('Save')->form();
        $none = $form['record[accent][none]'];
        self::assertInstanceOf(ChoiceFormField::class, $none);
        $none->untick();
        $form['record[accent][color]'] = '#E5534B';
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertSame('#e5534b', self::getContainer()->get(RecordFinderInterface::class)->find('shop', $shop->id)?->get('accent'));
    }

    public function testTheGridFiltersARangeOfTimes(): void
    {
        $records = self::getContainer()->get(RecordManagerInterface::class);
        $records->create(new RecordInput('shop', ['name' => 'Early', 'opens' => '07:00', 'brand' => '#111111']));
        $records->create(new RecordInput('shop', ['name' => 'Centro', 'opens' => '09:30', 'brand' => '#1f6feb', 'accent' => '#e5534b']));
        $records->create(new RecordInput('shop', ['name' => 'Late', 'opens' => '12:30', 'brand' => '#222222']));

        $crawler = $this->client->request('GET', '/o/shop', ['sort' => 'opens']);
        self::assertResponseIsSuccessful();
        self::assertSame(['07:00', '09:30', '12:30'], $this->cells('opens'));
        self::assertSame('time', $crawler->filter('input[name="fedaleForm[opens][from]"]')->attr('type'));
        self::assertCount(1, $crawler->filter('td[data-col-key="accent"] .gv-color-swatch'));

        $this->client->request('GET', '/o/shop', ['sort' => 'opens', 'fedaleForm' => ['opens' => ['from' => '09:00', 'to' => '12:30']]]);
        self::assertSame(['Centro', 'Late'], $this->cells('name'));

        $this->client->request('GET', '/o/shop/export', ['format' => 'csv', 'sort' => 'opens', 'cols' => 'name,opens,accent']);
        self::assertSame(
            ['Name,Opens,"Accent color"', 'Early,07:00,', 'Centro,09:30,#e5534b', 'Late,12:30,'],
            array_map(trim(...), explode("\n", trim((string) $this->client->getInternalResponse()->getContent(), "\u{FEFF}\n"))),
        );
    }

    /**
     * @return list<string>
     */
    private function cells(string $column): array
    {
        return $this->client->getCrawler()
            ->filter(\sprintf('#gv-tbody-record_shop td[data-col-key="%s"]', $column))
            ->each(static fn ($cell): string => trim($cell->text()));
    }
}
