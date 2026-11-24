<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Metadata\Application\Command\ConfigureFieldConditionCommand;
use App\Module\Metadata\Application\Command\ConfigureFieldSettingsCommand;
use App\Module\Metadata\Application\Command\ConfigureObjectLabelCommand;
use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\ConfigureFieldConditionHandler;
use App\Module\Metadata\Application\Handler\ConfigureFieldSettingsHandler;
use App\Module\Metadata\Application\Handler\ConfigureObjectLabelHandler;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A tags field through the record pipeline, against the real database: one jsonb column
 * holding the list of labels.
 */
final class TagsRecordsTest extends KernelTestCase
{
    private RecordManagerInterface $records;

    protected function setUp(): void
    {
        $container = self::getContainer();
        ($container->get(CreateObjectHandler::class))(new CreateObjectCommand('ticket', 'Ticket', 'Tickets'));
        ($container->get(CreateFieldHandler::class))(new CreateFieldCommand('ticket', 'subject', 'text', 'Subject', isRequired: true));
        ($container->get(CreateFieldHandler::class))(new CreateFieldCommand('ticket', 'labels', 'tags', 'Labels', settings: ['options' => ['Urgent', 'Later'], 'max' => 3, 'maxLength' => 20]));
        $this->records = $container->get(RecordManagerInterface::class);
    }

    public function testTheLabelsAreAJsonbArray(): void
    {
        $ticket = $this->records->create(new RecordInput('ticket', ['subject' => 'Printer', 'labels' => 'urgent, client   x, URGENT']));

        self::assertSame(['Urgent', 'client x'], $ticket->get('labels'));
        self::assertSame('jsonb', $this->connection()->fetchOne(
            "SELECT data_type FROM information_schema.columns WHERE table_schema = 'data' AND table_name = 'c_ticket' AND column_name = 'labels'",
        ));
        // Read back from the database, the jsonb column is what the field asks for: no change.
        self::assertSame([], self::getContainer()->get(SchemaSynchronizerInterface::class)->plan('ticket')->statements);
        // The column holds the array itself, not a string of it.
        self::assertSame('array', $this->connection()->fetchOne('SELECT jsonb_typeof(labels) FROM data.c_ticket WHERE id = ?', [$ticket->id]));

        $updated = $this->records->update($ticket->id, new RecordInput('ticket', ['labels' => ['later']]));
        self::assertSame(['Later'], $updated->get('labels'));
        // No label at all is no value.
        self::assertNull($this->records->update($ticket->id, new RecordInput('ticket', ['labels' => []]))->get('labels'));
    }

    public function testTheSettingsCheckTheLabels(): void
    {
        try {
            $this->records->create(new RecordInput('ticket', ['subject' => 'Printer', 'labels' => ['a', 'b', 'c', 'a label longer than twenty']]));
            self::fail('Too many labels, and one too long, must be refused.');
        } catch (RecordValidationFailed $e) {
            self::assertSame(['labels' => ['A label is at most 20 characters long.', 'Give at most 3 labels.']], $e->errors);
        }

        // Stricter settings check the values written from then on, not those stored.
        $ticket = $this->records->create(new RecordInput('ticket', ['subject' => 'Printer', 'labels' => ['urgent', 'someday']]));
        (self::getContainer()->get(ConfigureFieldSettingsHandler::class))(new ConfigureFieldSettingsCommand('ticket', 'labels', ['options' => ['Urgent', 'Later'], 'restrict' => true]));
        self::assertSame(['Urgent', 'someday'], self::getContainer()->get(RecordFinderInterface::class)->find('ticket', $ticket->id)?->get('labels'));
        $this->expectException(RecordValidationFailed::class);
        $this->records->update($ticket->id, new RecordInput('ticket', ['labels' => ['urgent', 'someday']]));
    }

    public function testATagsFieldIsNoLabelNoUniqueAndDecidesNoCondition(): void
    {
        $container = self::getContainer();
        $refusals = [
            'label' => fn () => ($container->get(ConfigureObjectLabelHandler::class))(new ConfigureObjectLabelCommand('ticket', 'labels')),
            'unique' => fn () => ($container->get(CreateFieldHandler::class))(new CreateFieldCommand('ticket', 'keywords', 'tags', 'Keywords', isUnique: true)),
            'condition' => fn () => ($container->get(ConfigureFieldConditionHandler::class))(new ConfigureFieldConditionCommand('ticket', 'subject', 'labels', ['Urgent'])),
        ];
        foreach ($refusals as $use => $refused) {
            try {
                $refused();
                self::fail(\sprintf('A tags field must be refused as a %s.', $use));
            } catch (MetadataException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
