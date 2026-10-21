<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Metadata\Application\Command\ConfigureObjectLabelCommand;
use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Handler\ConfigureObjectLabelHandler;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use App\Module\Record\Application\Grid\RecordGridSourceInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Exception\RecordDeleteRestricted;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordLookupInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use App\Tests\Support\CreatesRelatedObjects;
use Doctrine\DBAL\Connection;
use Fedale\GridviewBundle\DataSource\FilterCriterion;
use Fedale\GridviewBundle\DataSource\FilterOperator;
use Fedale\GridviewBundle\DataSource\GridQuery;
use Fedale\GridviewBundle\DataSource\SortDirection;
use Fedale\GridviewBundle\DataSource\SortOrder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Many-to-one relations end to end: metadata, foreign keys, validation, grid, delete rules.
 */
final class RelationsTest extends KernelTestCase
{
    use CreatesRelatedObjects;

    private RecordManagerInterface $records;
    private RecordFinderInterface $finder;

    protected function setUp(): void
    {
        self::createRelatedObjects(self::getContainer());
        $this->records = self::getContainer()->get(RecordManagerInterface::class);
        $this->finder = self::getContainer()->get(RecordFinderInterface::class);
    }

    public function testRelationsNeedAnExistingTargetAndAFreeInverseName(): void
    {
        $createField = self::getContainer()->get(CreateFieldHandler::class);

        try {
            $createField(new CreateFieldCommand('pet', 'vet', 'relation', 'Vet', settings: ['target' => 'doctor']));
            self::fail('Unknown target accepted.');
        } catch (InvalidMetadataDefinition $e) {
            self::assertStringContainsString('"doctor" does not exist', $e->getMessage());
        }

        foreach (['city', 'pets'] as $takenName) {
            try {
                $createField(new CreateFieldCommand('pet', 'carer', 'relation', 'Carer', settings: ['target' => 'person', 'inverseName' => $takenName]));
                self::fail(\sprintf('Inverse name "%s" accepted.', $takenName));
            } catch (InvalidMetadataDefinition $e) {
                self::assertStringContainsString(\sprintf('named "%s"', $takenName), $e->getMessage());
            }
        }
    }

    public function testRelationsHaveAForeignKeyAndAnIndex(): void
    {
        $connection = self::getContainer()->get(Connection::class);

        self::assertSame(
            ['fk_c_invoice_customer' => 'FOREIGN KEY (customer) REFERENCES data.c_person(id) ON DELETE RESTRICT'],
            $connection->fetchAllKeyValue("SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'data.c_invoice'::regclass AND contype = 'f'"),
        );
        self::assertSame(['idx_c_pet_owner'], $connection->fetchFirstColumn("SELECT indexname FROM pg_indexes WHERE schemaname = 'data' AND indexname = 'idx_c_pet_owner'"));

        foreach (['person', 'pet', 'visit', 'invoice'] as $object) {
            $plan = self::getContainer()->get(SchemaSynchronizerInterface::class)->plan($object, allowDestructive: true);
            self::assertTrue($plan->isEmpty(), $object.': '.implode("\n", $plan->statements));
        }
    }

    public function testReferencesMustPointAtLiveRecords(): void
    {
        $anna = $this->person('Anna');
        $rex = $this->records->create(new RecordInput('pet', ['name' => 'Rex', 'owner' => strtoupper($anna->id)]));
        self::assertSame($anna->id, $rex->get('owner'), 'ids are normalized to lowercase');

        self::assertSame(['owner' => ['This value is not a valid record reference.']], $this->errors(new RecordInput('pet', ['name' => 'Fido', 'owner' => 'anna'])));
        self::assertSame(['owner' => ['The selected record does not exist.']], $this->errors(new RecordInput('pet', ['name' => 'Fido', 'owner' => '01a14b6b-f371-729f-8e48-9a43d9f04c84'])));

        $bruno = $this->person('Bruno');
        $this->records->delete('person', $bruno->id);
        self::assertSame(['owner' => ['The selected record does not exist.']], $this->errors(new RecordInput('pet', ['name' => 'Fido', 'owner' => $bruno->id])));
    }

    public function testGridShowsFiltersAndSortsByTheRelatedLabel(): void
    {
        $anna = $this->person('Anna');
        $bruno = $this->person('Bruno');
        $this->records->create(new RecordInput('pet', ['name' => 'Rex', 'owner' => $bruno->id]));
        $this->records->create(new RecordInput('pet', ['name' => 'Fido', 'owner' => $anna->id]));
        $this->records->create(new RecordInput('pet', ['name' => 'Stray']));

        $source = self::getContainer()->get(RecordGridSourceInterface::class);
        $rows = [...$source->fetch(new GridQuery(source: ['object' => 'pet'], sort: [new SortOrder('owner', SortDirection::Asc)]))];

        self::assertSame(['Fido', 'Rex', 'Stray'], array_column($rows, 'name'));
        self::assertSame(['Anna', 'Bruno', null], array_column($rows, 'owner__label'));

        $filtered = [...$source->fetch(new GridQuery(source: ['object' => 'pet'], criteria: [new FilterCriterion('owner', FilterOperator::In, [$bruno->id])]))];
        self::assertSame(['Rex'], array_column($filtered, 'name'));
    }

    public function testLookupFindsRecordsByTheirLabel(): void
    {
        $this->person('Anna Bianchi', 'Milano');
        $this->person('Bruno Rossi', 'Roma');
        $lookup = self::getContainer()->get(RecordLookupInterface::class);

        self::assertSame(['Bruno Rossi'], array_map(static fn ($record) => $record->label, $lookup->search('person', 'ROSS')));
        self::assertSame(['Anna Bianchi', 'Bruno Rossi'], array_map(static fn ($record) => $record->label, $lookup->search('person', '')));

        // The label field is configurable.
        self::getContainer()->get(ConfigureObjectLabelHandler::class)(new ConfigureObjectLabelCommand('person', 'city'));
        self::assertSame(['Milano', 'Roma'], array_map(static fn ($record) => $record->label, $lookup->search('person', '')));
    }

    public function testDeletingClearsCascadesOrIsRefused(): void
    {
        $anna = $this->person('Anna');
        $rex = $this->records->create(new RecordInput('pet', ['name' => 'Rex', 'owner' => $anna->id]));
        $visit = $this->records->create(new RecordInput('visit', ['notes' => 'Vaccine', 'pet' => $rex->id]));
        $invoice = $this->records->create(new RecordInput('invoice', ['number' => 'F-1', 'customer' => $anna->id]));

        // restrict: refused while the invoice exists, and nothing changed.
        try {
            $this->records->delete('person', $anna->id);
            self::fail('A restricted delete went through.');
        } catch (RecordDeleteRestricted $e) {
            self::assertSame('This record cannot be deleted: 1 Invoices (Customer) still refer to it.', $e->getMessage());
        }
        self::assertNotNull($this->finder->find('person', $anna->id));
        self::assertSame($anna->id, $this->finder->find('pet', $rex->id)?->get('owner'));

        // set_null: the pet survives its owner.
        $this->records->delete('invoice', $invoice->id);
        $this->records->delete('person', $anna->id);
        self::assertNull($this->finder->find('pet', $rex->id)?->get('owner'));

        // cascade: the visits go with their pet.
        $this->records->delete('pet', $rex->id);
        self::assertNull($this->finder->find('visit', $visit->id));
    }

    public function testCascadeCyclesTerminate(): void
    {
        $anna = $this->person('Anna');
        $bruno = $this->records->create(new RecordInput('person', ['full_name' => 'Bruno', 'manager' => $anna->id]));
        $this->records->update($anna->id, new RecordInput('person', ['manager' => $bruno->id]));

        $this->records->delete('person', $anna->id);

        self::assertNull($this->finder->find('person', $anna->id));
        self::assertNull($this->finder->find('person', $bruno->id));
    }

    public function testTheInverseSideListsTheReferencingRecords(): void
    {
        $anna = $this->person('Anna');
        $this->records->create(new RecordInput('pet', ['name' => 'Rex', 'owner' => $anna->id]));
        $this->records->create(new RecordInput('pet', ['name' => 'Fido', 'owner' => $anna->id]));
        $this->records->create(new RecordInput('invoice', ['number' => 'F-1', 'customer' => $anna->id]));

        $groups = $this->finder->referencing('person', $anna->id, limit: 1);

        // One group per relation, in object order; the inverse label is configurable.
        self::assertSame(['Invoices (Customer)', 'Pets'], array_map(static fn ($group) => $group->label, $groups));
        self::assertSame([1, 2], array_map(static fn ($group) => $group->total, $groups));
        self::assertSame('F-1', $groups[0]->records[0]->label);
        self::assertCount(1, $groups[1]->records, 'limited');
    }

    private function person(string $name, ?string $city = null): RecordOutput
    {
        return $this->records->create(new RecordInput('person', ['full_name' => $name, 'city' => $city]));
    }

    /**
     * @return array<string, list<string>>
     */
    private function errors(RecordInput $input): array
    {
        try {
            $this->records->create($input);
        } catch (RecordValidationFailed $e) {
            return $e->errors;
        }

        self::fail('RecordValidationFailed expected.');
    }
}
