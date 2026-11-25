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
use App\Module\Record\UI\Grid\RecordGridFactory;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A tags field through HTTP: gridview's tags control in the form (a text input of labels
 * separated by commas, until its controller draws the chips), the labels in the grid and the
 * export.
 */
final class TagsFieldControllerTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);
        $container = self::getContainer();
        ($container->get(CreateObjectHandler::class))(new CreateObjectCommand('ticket', 'Ticket', 'Tickets'));
        ($container->get(CreateFieldHandler::class))(new CreateFieldCommand('ticket', 'subject', 'text', 'Subject', isRequired: true));
        ($container->get(CreateFieldHandler::class))(new CreateFieldCommand('ticket', 'labels', 'tags', 'Labels', settings: ['options' => ['Urgent', 'Later'], 'max' => 3, 'maxLength' => 20]));
    }

    public function testCreatesARecordWithItsLabels(): void
    {
        $crawler = $this->client->request('GET', '/o/ticket/new');
        $input = $crawler->filter('input[name="record[labels]"]');
        self::assertSame('text', $input->attr('type'));
        self::assertStringContainsString('gridview-tags', (string) $input->attr('data-controller'));
        self::assertSame('["Urgent","Later"]', $input->attr('data-gridview-tags-suggestions-value'));
        self::assertSame('3', $input->attr('data-gridview-tags-max-value'));
        self::assertSame('20', $input->attr('data-gridview-tags-max-length-value'));
        self::assertSame('/o/ticket/tags/labels', $input->attr('data-gridview-tags-url-value'));

        $form = $crawler->selectButton('Save')->form(['record[subject]' => 'Printer', 'record[labels]' => 'urgent, client x, Urgent']);
        $this->client->submit($form);
        self::assertResponseRedirects('/o/ticket', 303);

        $ticket = self::getContainer()->get(RecordFinderInterface::class)->latest('ticket')[0];
        self::assertSame(['Urgent', 'client x'], $ticket->get('labels'));

        $crawler = $this->client->request('GET', \sprintf('/o/ticket/%s/edit', $ticket->id));
        self::assertSame('Urgent, client x', $crawler->filter('input[name="record[labels]"]')->attr('value'));
    }

    public function testSuggestsTheLabelsTheRecordsHold(): void
    {
        $records = self::getContainer()->get(RecordManagerInterface::class);
        $records->create(new RecordInput('ticket', ['subject' => 'Printer', 'labels' => ['Urgent', 'client x']]));
        $records->create(new RecordInput('ticket', ['subject' => 'Copier', 'labels' => ['Client X', 'paper']]));
        $gone = $records->create(new RecordInput('ticket', ['subject' => 'Fax', 'labels' => ['clients']]));
        $records->delete('ticket', $gone->id);

        $this->client->request('GET', '/o/ticket/tags/labels', ['query' => 'CLI']);
        self::assertResponseIsSuccessful();
        self::assertSame(['Client X'], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->client->request('GET', '/o/ticket/tags/labels');
        self::assertSame(['Client X', 'paper', 'Urgent'], json_decode((string) $this->client->getResponse()->getContent(), true));
        // A LIKE wildcard is a character like any other.
        $this->client->request('GET', '/o/ticket/tags/labels', ['query' => '%']);
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true));

        $this->client->request('GET', '/o/ticket/tags/subject');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/o/ticket/tags/nothing');
        self::assertResponseStatusCodeSame(404);
    }

    public function testWithRestrictTheInputAsksForNoOtherLabels(): void
    {
        ($this->client->getContainer()->get(CreateFieldHandler::class))(new CreateFieldCommand('ticket', 'kind', 'tags', 'Kind', settings: ['options' => ['Bug', 'Feature'], 'restrict' => true]));

        $crawler = $this->client->request('GET', '/o/ticket/new');
        $input = $crawler->filter('input[name="record[kind]"]');
        self::assertSame('true', $input->attr('data-gridview-tags-restrict-value'));
        self::assertNull($input->attr('data-gridview-tags-url-value'));
    }

    public function testShowsTheFieldTypesErrorsOnTheField(): void
    {
        $crawler = $this->client->request('GET', '/o/ticket/new');
        $form = $crawler->selectButton('Save')->form(['record[subject]' => 'Printer', 'record[labels]' => 'a, b, c, d']);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-gv-field="labels"]', 'Give at most 3 labels.');
    }

    public function testWithoutSuggestionsTheFilterOffersTheLabelsInUse(): void
    {
        ($this->client->getContainer()->get(CreateFieldHandler::class))(new CreateFieldCommand('ticket', 'keywords', 'tags', 'Keywords'));
        $records = self::getContainer()->get(RecordManagerInterface::class);
        $records->create(new RecordInput('ticket', ['subject' => 'Printer', 'keywords' => ['paper', 'Toner']]));
        $records->create(new RecordInput('ticket', ['subject' => 'Copier', 'keywords' => ['toner']]));

        $crawler = $this->client->request('GET', '/o/ticket', ['sort' => 'subject']);
        self::assertSame(['paper', 'Toner'], $this->filterChoices($crawler->filter('select[name="fedaleForm[keywords][]"]')));
        $this->client->request('GET', '/o/ticket', ['sort' => 'subject', 'fedaleForm' => ['keywords' => ['Toner']]]);
        self::assertSame(['Copier', 'Printer'], $this->cells('subject'));
    }

    public function testPastTheMostLabelsInUseTheFilterTakesAText(): void
    {
        ($this->client->getContainer()->get(CreateFieldHandler::class))(new CreateFieldCommand('ticket', 'keywords', 'tags', 'Keywords'));
        $records = self::getContainer()->get(RecordManagerInterface::class);
        $records->create(new RecordInput('ticket', ['subject' => 'Printer', 'keywords' => array_map(static fn (int $i): string => 'k'.$i, range(0, RecordGridFactory::MAX_FILTER_LABELS))]));
        $records->create(new RecordInput('ticket', ['subject' => 'Copier', 'keywords' => ['toner']]));

        $crawler = $this->client->request('GET', '/o/ticket', ['sort' => 'subject']);
        self::assertCount(1, $crawler->filter('input[name="fedaleForm[keywords]"]'));
        $this->client->request('GET', '/o/ticket', ['sort' => 'subject', 'fedaleForm' => ['keywords' => 'TON']]);
        self::assertSame(['Copier'], $this->cells('subject'));
    }

    public function testTheGridAndTheExportShowTheLabels(): void
    {
        $records = self::getContainer()->get(RecordManagerInterface::class);
        $records->create(new RecordInput('ticket', ['subject' => 'Printer', 'labels' => ['Urgent', 'client x']]));
        $records->create(new RecordInput('ticket', ['subject' => 'Screen']));

        $crawler = $this->client->request('GET', '/o/ticket', ['sort' => 'subject']);
        self::assertResponseIsSuccessful();
        self::assertSame(['Urgent, client x', ''], $this->cells('labels'));
        self::assertSame(['Urgent', 'client x'], $crawler->filter('#gv-tbody-record_ticket td[data-col-key="labels"] .gv-badge')->each(static fn ($badge): string => $badge->text()));
        self::assertCount(0, $crawler->filter('table[data-gv="record_ticket"] thead th[data-col-key="labels"] a[href*="sort="]'));
        // The filter offers the suggestions; any of those picked, ignoring case.
        self::assertSame(['Urgent', 'Later'], $this->filterChoices($crawler->filter('select[name="fedaleForm[labels][]"][multiple]')));
        $this->client->request('GET', '/o/ticket', ['sort' => 'subject', 'fedaleForm' => ['labels' => ['Urgent', 'Later']]]);
        self::assertSame(['Printer'], $this->cells('subject'));

        $this->client->request('GET', '/o/ticket/export', ['format' => 'csv', 'sort' => 'subject', 'cols' => 'subject,labels']);
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Subject,Labels', 'Printer,"Urgent, client x"', 'Screen,'],
            array_map(trim(...), explode("\n", trim((string) $this->client->getInternalResponse()->getContent(), "\u{FEFF}\n"))),
        );
    }

    /**
     * @return list<string>
     */
    private function cells(string $column): array
    {
        return $this->client->getCrawler()
            ->filter(\sprintf('#gv-tbody-record_ticket td[data-col-key="%s"]', $column))
            ->each(static fn ($cell): string => trim($cell->text()));
    }

    /**
     * The values a relation filter offers: its controller draws them from a JSON attribute.
     *
     * @return list<mixed>
     */
    private function filterChoices(Crawler $select): array
    {
        $choices = json_decode((string) $select->attr('data-gridview-relation-filter-choices-value'), true);
        self::assertIsArray($choices);

        return array_column($choices, 'v');
    }
}
