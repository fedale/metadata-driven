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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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

        $form = $crawler->selectButton('Save')->form(['record[subject]' => 'Printer', 'record[labels]' => 'urgent, client x, Urgent']);
        $this->client->submit($form);
        self::assertResponseRedirects('/o/ticket', 303);

        $ticket = self::getContainer()->get(RecordFinderInterface::class)->latest('ticket')[0];
        self::assertSame(['Urgent', 'client x'], $ticket->get('labels'));

        $crawler = $this->client->request('GET', \sprintf('/o/ticket/%s/edit', $ticket->id));
        self::assertSame('Urgent, client x', $crawler->filter('input[name="record[labels]"]')->attr('value'));
    }

    public function testShowsTheFieldTypesErrorsOnTheField(): void
    {
        $crawler = $this->client->request('GET', '/o/ticket/new');
        $form = $crawler->selectButton('Save')->form(['record[subject]' => 'Printer', 'record[labels]' => 'a, b, c, d']);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-gv-field="labels"]', 'Give at most 3 labels.');
    }

    public function testTheGridAndTheExportShowTheLabels(): void
    {
        $records = self::getContainer()->get(RecordManagerInterface::class);
        $records->create(new RecordInput('ticket', ['subject' => 'Printer', 'labels' => ['Urgent', 'client x']]));
        $records->create(new RecordInput('ticket', ['subject' => 'Screen']));

        $crawler = $this->client->request('GET', '/o/ticket', ['sort' => 'subject']);
        self::assertResponseIsSuccessful();
        self::assertSame(['Urgent, client x', ''], $crawler->filter('#gv-tbody-record_ticket td[data-col-key="labels"]')->each(static fn ($cell): string => trim($cell->text())));
        self::assertCount(0, $crawler->filter('table[data-gv="record_ticket"] thead th[data-col-key="labels"] a[href*="sort="]'));

        $this->client->request('GET', '/o/ticket/export', ['format' => 'csv', 'sort' => 'subject', 'cols' => 'subject,labels']);
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Subject,Labels', 'Printer,"Urgent, client x"', 'Screen,'],
            array_map(trim(...), explode("\n", trim((string) $this->client->getInternalResponse()->getContent(), "\u{FEFF}\n"))),
        );
    }
}
