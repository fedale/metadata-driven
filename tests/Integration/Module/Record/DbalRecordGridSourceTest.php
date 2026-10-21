<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Metadata\Contract\Exception\FieldDefinitionNotFound;
use App\Module\Record\Application\Grid\RecordGridSourceInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\CreatesPetObject;
use Fedale\GridviewBundle\DataSource\FilterCriterion;
use Fedale\GridviewBundle\DataSource\FilterOperator;
use Fedale\GridviewBundle\DataSource\GridQuery;
use Fedale\GridviewBundle\DataSource\SortDirection;
use Fedale\GridviewBundle\DataSource\SortOrder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Normalized grid queries run against a real object table.
 */
final class DbalRecordGridSourceTest extends KernelTestCase
{
    use CreatesPetObject;

    private RecordGridSourceInterface $source;

    protected function setUp(): void
    {
        $container = self::getContainer();
        self::createPetObject($container);
        $this->source = $container->get(RecordGridSourceInterface::class);

        $records = $container->get(RecordManagerInterface::class);
        $pets = [
            ['name' => 'Rex', 'species' => 'dog', 'weight' => '12.5', 'age' => 3, 'vaccinated' => true, 'born_on' => '2023-04-01', 'chip_code' => 'IT_001'],
            ['name' => 'Fido', 'species' => 'dog', 'weight' => '30', 'age' => 7, 'vaccinated' => false, 'born_on' => '2019-01-15', 'chip_code' => 'IT-002'],
            ['name' => 'Micia', 'species' => 'cat', 'weight' => '4.2', 'age' => 4, 'vaccinated' => true, 'born_on' => '2022-11-30', 'chip_code' => 'SALE-50%OFF'],
            ['name' => 'Ghost', 'species' => 'cat', 'age' => 1],
        ];
        foreach ($pets as $values) {
            $record = $records->create(new RecordInput('pet', $values));
        }
        // The last one is deleted: grids never show it.
        $records->delete('pet', $record->id);
    }

    public function testRowsAreTypedArraysKeyedByField(): void
    {
        $rows = $this->rows(new GridQuery(source: ['object' => 'pet'], sort: [new SortOrder('name', SortDirection::Asc)]));

        self::assertSame(['Fido', 'Micia', 'Rex'], array_column($rows, 'name'));
        self::assertTrue(\is_string($rows[0]['id']));
        self::assertSame('30.00', $rows[0]['weight']);
        self::assertSame(7, $rows[0]['age']);
        self::assertFalse($rows[0]['vaccinated']);
        self::assertEquals(new \DateTimeImmutable('2019-01-15'), $rows[0]['born_on']);
    }

    public function testCountIgnoresTheWindow(): void
    {
        $query = new GridQuery(source: ['object' => 'pet'], sort: [new SortOrder('age', SortDirection::Desc)]);

        self::assertSame(3, $this->source->count($query->withWindow(1, 1)));
        self::assertSame(['Micia'], array_column($this->rows($query->withWindow(1, 1)), 'name'));
    }

    /**
     * @param list<FilterCriterion> $criteria
     * @param list<string>          $expected names, sorted
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('criteria')]
    public function testCriteria(array $criteria, array $expected): void
    {
        $query = new GridQuery(source: ['object' => 'pet'], criteria: $criteria, sort: [new SortOrder('name', SortDirection::Asc)]);

        self::assertSame($expected, array_column($this->rows($query), 'name'));
        self::assertSame(\count($expected), $this->source->count($query));
    }

    /**
     * @return iterable<string, array{list<FilterCriterion>, list<string>}>
     */
    public static function criteria(): iterable
    {
        yield 'contains, case-insensitive' => [[new FilterCriterion('name', FilterOperator::Contains, 'i', caseInsensitive: true)], ['Fido', 'Micia']];
        yield 'not contains' => [[new FilterCriterion('name', FilterOperator::NotContains, 'e')], ['Fido', 'Micia']];
        yield 'starts with' => [[new FilterCriterion('name', FilterOperator::StartsWith, 'Mi')], ['Micia']];
        yield 'ends with' => [[new FilterCriterion('name', FilterOperator::EndsWith, 'ex')], ['Rex']];
        yield 'underscore is literal' => [[new FilterCriterion('chip_code', FilterOperator::Contains, 'IT_')], ['Rex']];
        yield 'eq, case-insensitive' => [[new FilterCriterion('name', FilterOperator::Eq, 'REX', caseInsensitive: true)], ['Rex']];
        yield 'choice in' => [[new FilterCriterion('species', FilterOperator::In, ['cat'])], ['Micia']];
        yield 'integer with fractional bound' => [[new FilterCriterion('age', FilterOperator::Gt, 3.5)], ['Fido', 'Micia']];
        yield 'decimal between' => [[new FilterCriterion('weight', FilterOperator::Between, [4, 13])], ['Micia', 'Rex']];
        yield 'neq' => [[new FilterCriterion('age', FilterOperator::Neq, 3)], ['Fido', 'Micia']];
        yield 'boolean' => [[new FilterCriterion('vaccinated', FilterOperator::Eq, true)], ['Micia', 'Rex']];
        yield 'date range' => [[
            new FilterCriterion('born_on', FilterOperator::Gte, new \DateTimeImmutable('2022-01-01')),
            new FilterCriterion('born_on', FilterOperator::Lte, new \DateTimeImmutable('2022-12-31 23:59:59')),
        ], ['Micia']];
        yield 'criteria are AND-ed' => [[
            new FilterCriterion('species', FilterOperator::Eq, 'dog'),
            new FilterCriterion('age', FilterOperator::Lte, 5),
        ], ['Rex']];
    }

    public function testGlobalSearchMatchesAnyFieldLiterally(): void
    {
        $query = new GridQuery(source: ['object' => 'pet'], searchTerm: 'REX', searchFields: ['name', 'chip_code']);
        self::assertSame(['Rex'], array_column($this->rows($query), 'name'));

        // "%" is a literal character, not a wildcard.
        $query = new GridQuery(source: ['object' => 'pet'], searchTerm: '50%off', searchFields: ['name', 'chip_code']);
        self::assertSame(['Micia'], array_column($this->rows($query), 'name'));
        $query = new GridQuery(source: ['object' => 'pet'], searchTerm: '5%f', searchFields: ['name', 'chip_code']);
        self::assertSame([], $this->rows($query));
    }

    public function testUnknownAttributesNeverReachTheSql(): void
    {
        $this->expectException(FieldDefinitionNotFound::class);

        $this->rows(new GridQuery(source: ['object' => 'pet'], criteria: [new FilterCriterion('name; DROP TABLE x', FilterOperator::Eq, 'a')]));
    }

    public function testUnknownSortAttributesAreRejected(): void
    {
        $this->expectException(FieldDefinitionNotFound::class);

        $this->rows(new GridQuery(source: ['object' => 'pet'], sort: [new SortOrder('deleted_at', SortDirection::Asc)]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(GridQuery $query): array
    {
        return array_values([...$this->source->fetch($query)]);
    }
}
