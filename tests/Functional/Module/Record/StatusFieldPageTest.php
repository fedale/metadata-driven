<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A Status field on a record's page: read-only in the form, its transitions as buttons.
 */
final class StatusFieldPageTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $metadata = self::getContainer()->get(MetadataManagerInterface::class);
        $metadata->createObject('invoice', 'Invoice', 'Invoices');
        $metadata->createField('invoice', 'number', 'text', 'Number');
        $metadata->createField('invoice', 'state', 'status', 'State', settings: [
            'states' => [['value' => 'draft', 'label' => 'Draft'], ['value' => 'sent', 'label' => 'Sent']],
            'transitions' => [
                ['name' => 'send', 'label' => 'Send', 'from' => ['draft'], 'to' => 'sent', 'roles' => []],
                ['name' => 'recall', 'label' => 'Recall', 'from' => ['sent'], 'to' => 'draft', 'roles' => ['ROLE_BOSS']],
            ],
        ]);
    }

    public function testTheButtonsTakeTheTransitionsTheFormChangesItNot(): void
    {
        self::signIn($this->client, 'anna@example.com', ['record.invoice.read', 'record.invoice.update']);
        $invoice = self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('invoice', ['number' => 'A1']));

        $crawler = $this->client->request('GET', '/o/invoice/'.$invoice->id.'/edit');
        self::assertSelectorExists('select[name="record[state]"][disabled]');
        self::assertSelectorTextContains('.md-sheet__actions', 'Send');
        self::assertSelectorNotExists('.md-transition:contains("Recall")');

        $this->client->submit($crawler->filter('form[id^="md-transition-"]')->form());
        self::assertResponseRedirects('/o/invoice/'.$invoice->id.'/edit', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'its new state is saved');
        self::assertSame('sent', self::getContainer()->get(RecordFinderInterface::class)->find('invoice', $invoice->id)?->get('state'));
        // From sent, Recall is the boss's: no button for Anna, and its address refuses her.
        self::assertSelectorNotExists('form[id^="md-transition-"]');
        $this->client->request('POST', '/o/invoice/'.$invoice->id.'/transition/state/recall', ['transition' => ['_token' => 'csrf-token']]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--error', 'You may not take "Recall".');
    }
}
