<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordFilter;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Exception\InvalidRecordSearch;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\CreatesAccountObject;
use App\Tests\Support\CreatesRelatedObjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The records of an object searched on its fields, as a workflow's search does: each type
 * compared as itself, the first records in their order and how many there are in all.
 */
final class RecordSearchTest extends KernelTestCase
{
    use CreatesAccountObject;
    use CreatesRelatedObjects;

    private RecordFinderInterface $finder;

    /** @var array<string, string> name => id */
    private array $people = [];

    protected function setUp(): void
    {
        $container = self::getContainer();
        self::createRelatedObjects($container);
        self::createAccountObject($container);
        $metadata = $container->get(MetadataManagerInterface::class);
        $metadata->createField('person', 'age', 'number', 'Age');
        $metadata->createField('person', 'born_on', 'date', 'Born on');
        $records = $container->get(RecordManagerInterface::class);
        foreach ([['Ada', 'Pisa', 36, '1815-12-10'], ['Bob', 'Roma', 7, '2018-01-02'], ['Carla', null, 52, null], ['Dario', 'pisa', 7, '2018-05-05']] as [$name, $city, $age, $born]) {
            $this->people[$name] = $records->create(new RecordInput('person', ['full_name' => $name, 'city' => $city, 'age' => $age, 'born_on' => $born]))->id;
        }
        $records->create(new RecordInput('pet', ['name' => 'Rex', 'owner' => $this->people['Ada']]));
        $records->create(new RecordInput('pet', ['name' => 'Tom', 'owner' => $this->people['Bob']]));
        $records->create(new RecordInput('account', ['username' => 'mario', 'password' => 'secret123', 'active' => false]));
        $this->finder = $container->get(RecordFinderInterface::class);
    }

    public function testComparesEachTypeAsItself(): void
    {
        self::assertSame(['Bob', 'Dario'], $this->names([new RecordFilter('age', 'lt', '10')]));
        self::assertSame(['Ada', 'Carla'], $this->names([new RecordFilter('age', 'between', [30, 60])]));
        self::assertSame(['Bob', 'Dario'], $this->names([new RecordFilter('born_on', 'gte', '2000-01-01')]));
        // Text: as written, or case ignored for contains and the like.
        self::assertSame(['Ada'], $this->names([new RecordFilter('city', 'eq', 'Pisa')]));
        self::assertSame(['Ada', 'Dario'], $this->names([new RecordFilter('city', 'starts_with', 'PI')]));
        self::assertSame(['Bob', 'Carla'], $this->names([new RecordFilter('city', 'not_contains', 'pisa')]));
        self::assertSame(['Carla'], $this->names([new RecordFilter('city', 'empty')]));
        self::assertSame(['Bob', 'Carla', 'Dario'], $this->names([new RecordFilter('city', 'neq', 'Pisa')]));
        self::assertSame(['Ada', 'Bob'], $this->names([new RecordFilter('id', 'in', [$this->people['Ada'], $this->people['Bob']])]));
        // All the filters hold.
        self::assertSame(['Dario'], $this->names([new RecordFilter('age', 'eq', 7), new RecordFilter('city', 'contains', 'is')]));

        $pets = $this->finder->search('pet', [new RecordFilter('owner', 'eq', $this->people['Ada'])]);
        self::assertSame(['Rex'], array_map(static fn (RecordOutput $pet): mixed => $pet->get('name'), $pets->records));
        self::assertSame(1, $this->finder->search('account', [new RecordFilter('active', 'eq', 'false')])->total);
    }

    public function testTakesTheFirstInItsOrderAndCountsThemAll(): void
    {
        $result = $this->finder->search('person', [new RecordFilter('city', 'not_empty')], 'age', true, 2);

        self::assertSame(3, $result->total);
        self::assertSame(['Ada', 'Bob'], array_map(static fn (RecordOutput $person): mixed => $person->get('full_name'), $result->records));
    }

    public function testRefusesWhatWouldFailTheQuery(): void
    {
        foreach ([
            [new RecordFilter('age', 'gt', 'many'), '"many" is no number, for "age".'],
            [new RecordFilter('born_on', 'eq', '2026-02-30'), '"2026-02-30" is no date (2026-12-31), for "born_on".'],
            [new RecordFilter('id', 'eq', 'nope'), '"nope" is no id, for "id".'],
            [new RecordFilter('nickname', 'eq', 'x'), 'Object "person" has no field "nickname".'],
            [new RecordFilter('city', 'like', 'x'), '"like" is no operator: one of eq, neq, lt, lte, gt, gte, contains, not_contains, starts_with, ends_with, in, between, empty, not_empty.'],
            [new RecordFilter('age', 'between', [1]), '"between" on "age" takes two values: the lowest and the highest.'],
        ] as [$filter, $message]) {
            try {
                $this->finder->search('person', [$filter]);
                self::fail($message);
            } catch (InvalidRecordSearch $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
        $this->expectExceptionMessage('Field "password" of "account" cannot be searched on: a many-to-many, file or password field.');
        $this->finder->search('account', [new RecordFilter('password', 'empty')]);
    }

    /**
     * @param list<RecordFilter> $filters
     *
     * @return list<mixed>
     */
    private function names(array $filters): array
    {
        return array_map(static fn (RecordOutput $person): mixed => $person->get('full_name'), $this->finder->search('person', $filters, 'full_name')->records);
    }
}
