<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Exception\RecordNotFound;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\CreatesPetObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Full record pipeline against PostgreSQL: RecordInput → denormalization → validation → storage.
 */
final class RecordManagerTest extends KernelTestCase
{
    use CreatesPetObject;

    private RecordManagerInterface $records;
    private RecordFinderInterface $finder;

    protected function setUp(): void
    {
        $container = self::getContainer();
        self::createPetObject($container);
        $this->records = $container->get(RecordManagerInterface::class);
        $this->finder = $container->get(RecordFinderInterface::class);
    }

    public function testCreatesARecordWithTypedValues(): void
    {
        $record = $this->records->create(new RecordInput('pet', [
            'name' => '  Rex ',
            'species' => 'dog',
            'weight' => '12.5',
            'age' => '3',
            'vaccinated' => 'on',
            'born_on' => '2023-04-01',
            'chip_code' => 'IT-001',
            'owner_email' => 'anna@example.com',
        ]));

        self::assertSame('pet', $record->objectName);
        self::assertSame('Rex', $record->get('name'));
        self::assertSame('dog', $record->get('species'));
        self::assertSame('12.50', $record->get('weight'));
        self::assertSame(3, $record->get('age'));
        self::assertTrue($record->get('vaccinated'));
        self::assertEquals(new \DateTimeImmutable('2023-04-01 00:00:00'), $record->get('born_on'));
        self::assertSame('anna@example.com', $record->get('owner_email'));
        self::assertEquals($record, $this->finder->find('pet', $record->id));
    }

    public function testMissingFieldsAreNullOnCreate(): void
    {
        $record = $this->records->create(new RecordInput('pet', ['name' => 'Rex']));

        self::assertNull($record->get('species'));
        self::assertNull($record->get('vaccinated'));
    }

    public function testReportsEveryInvalidFieldAtOnce(): void
    {
        $errors = $this->validationErrors(new RecordInput('pet', [
            'name' => str_repeat('x', 21),
            'species' => 'fish',
            'weight' => '1.234',
            'age' => '3.5',
            'vaccinated' => 'maybe',
            'born_on' => '2026-02-31',
            'owner_email' => 'not-an-email',
            'color' => 'brown',
        ]));

        self::assertSame([
            'color',
            'weight',
            'age',
            'vaccinated',
            'born_on',
            'name',
            'species',
            'owner_email',
        ], array_keys($errors));
        self::assertSame(['This value should have at most 2 decimal places.'], $errors['weight']);
        self::assertSame(['This value should be a whole number.'], $errors['age']);
        self::assertSame(['This value is not a valid date.'], $errors['born_on']);
        self::assertSame(['This field does not exist.'], $errors['color']);
        self::assertSame(['The value you selected is not a valid choice.'], $errors['species']);
    }

    public function testRequiredFieldsAreEnforced(): void
    {
        self::assertSame(['name' => ['This value is required.']], $this->validationErrors(new RecordInput('pet', ['name' => '   '])));
    }

    public function testUniqueFieldsAreEnforced(): void
    {
        $first = $this->records->create(new RecordInput('pet', ['name' => 'Rex', 'chip_code' => 'IT-001']));

        self::assertSame(
            ['chip_code' => ['This value is already used.']],
            $this->validationErrors(new RecordInput('pet', ['name' => 'Fido', 'chip_code' => 'IT-001'])),
        );

        // The record keeping its own value is not a conflict.
        $updated = $this->records->update($first->id, new RecordInput('pet', ['chip_code' => 'IT-001', 'age' => 4]));
        self::assertSame(4, $updated->get('age'));
    }

    public function testUpdateOnlyChangesProvidedFields(): void
    {
        $record = $this->records->create(new RecordInput('pet', ['name' => 'Rex', 'species' => 'dog', 'age' => 3]));

        $updated = $this->records->update($record->id, new RecordInput('pet', ['age' => 4, 'species' => null]));

        self::assertSame('Rex', $updated->get('name'));
        self::assertNull($updated->get('species'));
        self::assertSame(4, $updated->get('age'));
        self::assertGreaterThanOrEqual($record->updatedAt, $updated->updatedAt);
    }

    public function testUpdateCannotClearARequiredField(): void
    {
        $record = $this->records->create(new RecordInput('pet', ['name' => 'Rex']));

        $this->expectException(RecordValidationFailed::class);

        $this->records->update($record->id, new RecordInput('pet', ['name' => null]));
    }

    public function testDeletedRecordsDisappear(): void
    {
        $record = $this->records->create(new RecordInput('pet', ['name' => 'Rex']));

        $this->records->delete('pet', $record->id);

        self::assertNull($this->finder->find('pet', $record->id));
        self::assertSame([], $this->finder->latest('pet'));

        $this->expectException(RecordNotFound::class);
        $this->records->update($record->id, new RecordInput('pet', ['age' => 1]));
    }

    public function testLatestListsNewestFirst(): void
    {
        $this->records->create(new RecordInput('pet', ['name' => 'First']));
        $this->records->create(new RecordInput('pet', ['name' => 'Second']));

        self::assertSame(['Second', 'First'], array_map(
            static fn ($record) => $record->get('name'),
            $this->finder->latest('pet'),
        ));
    }

    public function testFieldsNamedAfterSqlKeywordsWork(): void
    {
        $container = self::getContainer();
        $container->get(CreateObjectHandler::class)(new CreateObjectCommand('user', 'User', 'Users'));
        $container->get(CreateFieldHandler::class)(new CreateFieldCommand('user', 'order', 'number', 'Order'));
        $container->get(CreateFieldHandler::class)(new CreateFieldCommand('user', 'select', 'text', 'Select', isUnique: true));

        $record = $this->records->create(new RecordInput('user', ['order' => 7, 'select' => 'a']));
        $this->records->update($record->id, new RecordInput('user', ['order' => 8]));

        self::assertSame(8, $this->finder->find('user', $record->id)?->get('order'));
        self::assertArrayHasKey('select', $this->validationErrors(new RecordInput('user', ['select' => 'a'])));
    }

    /**
     * @return array<string, list<string>>
     */
    private function validationErrors(RecordInput $input): array
    {
        try {
            $this->records->create($input);
        } catch (RecordValidationFailed $e) {
            return $e->errors;
        }

        self::fail('RecordValidationFailed expected.');
    }
}
