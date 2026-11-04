<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Metadata\Contract\Exception\FieldDefinitionNotFound;
use App\Module\Record\Application\Grid\RecordGridSourceInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\CreatesPetObject;
use App\Tests\Support\CreatesTaggedContacts;
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
    use CreatesTaggedContacts;

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

    public function testAManyToManyFieldBringsTheLabelsOfTheLinkedRecords(): void
    {
        [$vip, $press, $gone] = $this->taggedContacts();

        $rows = $this->rows($this->contacts());

        self::assertSame(['Anna', 'Bruno', 'Carla', 'Dario'], array_column($rows, 'full_name'));
        // Sorted by label, joined by ", "; a deleted record is left out; nothing is null.
        self::assertSame(['Press, VIP', 'Press', null, null], array_column($rows, 'tags__label'));
        // The ids, in the order of the labels.
        self::assertSame([[$press, $vip], [$press], [], []], array_column($rows, 'tags'));
        self::assertNotContains($gone, array_merge(...array_column($rows, 'tags')));
    }

    /**
     * @param list<string> $tags     names of the tags filtered on
     * @param list<string> $expected names of the contacts, sorted
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('linkCriteria')]
    public function testAManyToManyFieldIsFilteredOnTheLinkedRecords(FilterOperator $operator, array $tags, array $expected): void
    {
        $ids = array_combine(['VIP', 'Press', 'Gone'], $this->taggedContacts());
        $values = array_map(static fn (string $tag): string => $ids[$tag] ?? $tag, $tags);
        $query = $this->contacts([new FilterCriterion('tags', $operator, FilterOperator::Eq === $operator ? $values[0] : $values)]);

        self::assertSame($expected, array_column($this->rows($query), 'full_name'));
        self::assertSame(\count($expected), $this->source->count($query));
    }

    /**
     * @return iterable<string, array{FilterOperator, list<string>, list<string>}>
     */
    public static function linkCriteria(): iterable
    {
        yield 'one record' => [FilterOperator::In, ['VIP'], ['Anna']];
        yield 'eq' => [FilterOperator::Eq, ['Press'], ['Anna', 'Bruno']];
        yield 'any of several, each record once' => [FilterOperator::In, ['VIP', 'Press'], ['Anna', 'Bruno']];
        yield 'a deleted record' => [FilterOperator::In, ['Gone'], []];
        yield 'not an id' => [FilterOperator::In, ['nope'], []];
    }

    public function testAManyToManyFieldIsNeitherSortedNorSearched(): void
    {
        $this->taggedContacts();

        $this->assertRefused(fn () => $this->rows($this->contacts(sort: [new SortOrder('tags', SortDirection::Asc)])));
        $this->assertRefused(fn () => $this->rows(new GridQuery(source: ['object' => 'contact'], searchTerm: 'vip', searchFields: ['tags'])));
        $this->assertRefused(fn () => $this->rows(new GridQuery(source: ['object' => 'contact', 'virtual' => ['line' => ['full_name', 'tags']]], criteria: [new FilterCriterion('line', FilterOperator::Contains, 'vip')])));
        $this->assertRefused(fn () => $this->rows($this->contacts([new FilterCriterion('tags', FilterOperator::Contains, 'vip')])));
    }

    /**
     * Anna is tagged VIP and Press, Bruno Press, Carla nothing, Dario with a tag deleted since.
     *
     * @return array{string, string, string} the ids of the tags VIP, Press and of the deleted one
     */
    private function taggedContacts(): array
    {
        $container = self::getContainer();
        self::createTaggedContacts($container);
        $records = $container->get(RecordManagerInterface::class);
        [$vip, $press, $gone] = array_map(
            static fn (string $name): string => $records->create(new RecordInput('tag', ['name' => $name]))->id,
            ['VIP', 'Press', 'Gone'],
        );
        foreach (['Anna' => [$vip, $press], 'Bruno' => [$press], 'Carla' => [], 'Dario' => [$gone]] as $name => $tags) {
            $records->create(new RecordInput('contact', ['full_name' => $name, 'tags' => $tags]));
        }
        $records->delete('tag', $gone);

        return [$vip, $press, $gone];
    }

    /**
     * @param list<FilterCriterion> $criteria
     * @param list<SortOrder>       $sort
     */
    private function contacts(array $criteria = [], ?array $sort = null): GridQuery
    {
        return new GridQuery(source: ['object' => 'contact'], criteria: $criteria, sort: $sort ?? [new SortOrder('full_name', SortDirection::Asc)]);
    }

    private function assertRefused(callable $query): void
    {
        try {
            $query();
            self::fail('The query was not refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"tags" of "contact" is many-to-many', $e->getMessage());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(GridQuery $query): array
    {
        return array_values([...$this->source->fetch($query)]);
    }
}
