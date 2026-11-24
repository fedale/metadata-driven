<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Metadata\Application\Command\ConfigureFieldConditionCommand;
use App\Module\Metadata\Application\Command\ConfigureFieldSettingsCommand;
use App\Module\Metadata\Application\Command\ConfigureObjectLabelCommand;
use App\Module\Metadata\Application\Command\ConfigureObjectSearchCommand;
use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\ConfigureFieldConditionHandler;
use App\Module\Metadata\Application\Handler\ConfigureFieldSettingsHandler;
use App\Module\Metadata\Application\Handler\ConfigureObjectLabelHandler;
use App\Module\Metadata\Application\Handler\ConfigureObjectSearchHandler;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Application\Grid\RecordGridSourceInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use App\Module\Search\Contract\SearchConfigurationInterface;
use Doctrine\DBAL\Connection;
use Fedale\GridviewBundle\DataSource\FilterCriterion;
use Fedale\GridviewBundle\DataSource\FilterOperator;
use Fedale\GridviewBundle\DataSource\GridQuery;
use Fedale\GridviewBundle\DataSource\SortDirection;
use Fedale\GridviewBundle\DataSource\SortOrder;
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

    public function testTheGridFiltersOnTheLabelsIgnoringCase(): void
    {
        $this->tickets();
        $subjects = fn (FilterCriterion ...$criteria): array => $this->subjects(new GridQuery(source: ['object' => 'ticket'], criteria: array_values($criteria), sort: [new SortOrder('subject', SortDirection::Asc)]));

        self::assertSame(['Printer', 'Scanner'], $subjects(new FilterCriterion('labels', FilterOperator::Eq, 'URGENT')));
        self::assertSame(['Printer', 'Scanner', 'Screen'], $subjects(new FilterCriterion('labels', FilterOperator::In, ['client x', 'later'])));
        self::assertSame(['Printer', 'Scanner', 'Screen'], $subjects(new FilterCriterion('labels', FilterOperator::Eq, true)));
        self::assertSame(['Mouse'], $subjects(new FilterCriterion('labels', FilterOperator::Eq, false)));
        self::assertSame(['Printer', 'Scanner'], $subjects(new FilterCriterion('labels', FilterOperator::Contains, 'IENT')));
        self::assertSame(['Mouse', 'Screen'], $subjects(new FilterCriterion('labels', FilterOperator::NotContains, 'client')));
        self::assertSame([], $subjects(new FilterCriterion('labels', FilterOperator::In, [])));

        $this->expectException(\InvalidArgumentException::class);
        $subjects(new FilterCriterion('labels', FilterOperator::Gt, 'a'));
    }

    public function testTheSearchMatchesALabel(): void
    {
        $this->tickets();

        // A tags field is searched by default, as a text field is.
        $ticket = self::getContainer()->get(MetadataRegistryInterface::class)->getObject('ticket');
        self::assertSame(['subject', 'labels'], self::getContainer()->get(SearchConfigurationInterface::class)->fieldsFor($ticket));
        self::assertSame(['Printer', 'Scanner'], $this->subjects(new GridQuery(source: ['object' => 'ticket'], searchTerm: 'clie', searchFields: ['subject', 'labels'], sort: [new SortOrder('subject', SortDirection::Asc)])));
        (self::getContainer()->get(ConfigureObjectSearchHandler::class))(new ConfigureObjectSearchCommand('ticket', 'phrase', ['labels']));
        self::assertSame(['labels'], self::getContainer()->get(MetadataRegistryInterface::class)->getObject('ticket')->searchFields);
    }

    public function testTheLabelsInUseAreThoseOfTheLiveRecords(): void
    {
        $this->tickets();
        $gone = $this->records->create(new RecordInput('ticket', ['subject' => 'Fax', 'labels' => ['obsolete']]));
        $this->records->delete('ticket', $gone->id);
        $object = self::getContainer()->get(MetadataRegistryInterface::class)->getObject('ticket');
        $source = self::getContainer()->get(RecordGridSourceInterface::class);

        // One spelling of "client x"; nothing of the deleted record.
        self::assertSame(['Client X', 'Later', 'Urgent'], $source->labelsInUse($object, 'labels', 10));
        self::assertSame(['Client X'], $source->labelsInUse($object, 'labels', 1));
    }

    private function tickets(): void
    {
        $this->records->create(new RecordInput('ticket', ['subject' => 'Printer', 'labels' => ['Urgent', 'client x']]));
        $this->records->create(new RecordInput('ticket', ['subject' => 'Scanner', 'labels' => ['urgent', 'Client X']]));
        $this->records->create(new RecordInput('ticket', ['subject' => 'Screen', 'labels' => ['later']]));
        $this->records->create(new RecordInput('ticket', ['subject' => 'Mouse']));
    }

    /**
     * @return list<mixed>
     */
    private function subjects(GridQuery $query): array
    {
        return array_column([...self::getContainer()->get(RecordGridSourceInterface::class)->fetch($query)], 'subject');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
