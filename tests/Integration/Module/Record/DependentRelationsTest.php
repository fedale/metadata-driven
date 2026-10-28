<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordLabel;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordLookupInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\CreatesPlaces;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A cascade of relations (region, province, municipality) against the real database.
 */
final class DependentRelationsTest extends KernelTestCase
{
    use CreatesPlaces;

    /** @var array<string, string> */
    private array $ids;
    private RecordManagerInterface $records;

    protected function setUp(): void
    {
        $this->ids = self::createPlaces(self::getContainer());
        $this->records = self::getContainer()->get(RecordManagerInterface::class);
    }

    public function testTheLookupListsTheRecordsOfTheChosenParent(): void
    {
        $lookup = self::getContainer()->get(RecordLookupInterface::class);

        self::assertSame(['Milano', 'Sesto San Giovanni'], self::names($lookup->search('municipality', '', 20, ['province' => $this->ids['mi']])));
        self::assertSame(['Sesto San Giovanni'], self::names($lookup->search('municipality', 'sesto', 20, ['province' => $this->ids['mi']])));
        self::assertSame(['Roma'], self::names($lookup->search('municipality', '', 20, ['province' => $this->ids['rm']])));

        $this->expectException(\InvalidArgumentException::class);
        $lookup->search('municipality', '', 20, ['name' => 'Roma']);
    }

    /**
     * @param list<RecordLabel> $labels
     *
     * @return list<string>
     */
    private static function names(array $labels): array
    {
        return array_map(static fn (RecordLabel $label): string => $label->label, $labels);
    }

    public function testACoherentCascadeIsSaved(): void
    {
        $venue = $this->records->create(new RecordInput('venue', [
            'name' => 'Teatro', 'region' => $this->ids['lombardia'], 'province' => $this->ids['mi'], 'municipality' => $this->ids['milano'],
        ]));

        self::assertSame($this->ids['milano'], $venue->get('municipality'));
    }

    public function testARecordOfAnotherParentIsRefused(): void
    {
        try {
            $this->records->create(new RecordInput('venue', [
                'name' => 'Teatro', 'region' => $this->ids['lombardia'], 'province' => $this->ids['rm'], 'municipality' => $this->ids['milano'],
            ]));
            self::fail('Roma is not in Lombardia, Milano is not in Roma.');
        } catch (RecordValidationFailed $e) {
            self::assertSame(['The selected record does not belong to the chosen region.'], $e->errors['province']);
            self::assertSame(['The selected record does not belong to the chosen province.'], $e->errors['municipality']);
        }
    }

    public function testAChildNeedsItsParent(): void
    {
        try {
            $this->records->create(new RecordInput('venue', ['name' => 'Teatro', 'municipality' => $this->ids['roma']]));
            self::fail('A municipality without its province must be refused.');
        } catch (RecordValidationFailed $e) {
            self::assertSame(['Choose the province first.'], $e->errors['municipality']);
        }
    }

    public function testAnUpdateOfTheParentAloneChecksTheStoredChild(): void
    {
        $venue = $this->records->create(new RecordInput('venue', [
            'name' => 'Teatro', 'region' => $this->ids['lombardia'], 'province' => $this->ids['mi'], 'municipality' => $this->ids['milano'],
        ]));

        // Moving to Roma without changing the municipality would leave Milano in Roma.
        try {
            $this->records->update($venue->id, new RecordInput('venue', ['region' => $this->ids['lazio'], 'province' => $this->ids['rm']]));
            self::fail('The stored municipality is not in Roma.');
        } catch (RecordValidationFailed $e) {
            self::assertArrayHasKey('municipality', $e->errors);
        }

        $moved = $this->records->update($venue->id, new RecordInput('venue', ['region' => $this->ids['lazio'], 'province' => $this->ids['rm'], 'municipality' => $this->ids['roma']]));
        self::assertSame($this->ids['roma'], $moved->get('municipality'));
        // The name alone leaves the cascade as it is.
        self::assertSame('Teatro Argentina', $this->records->update($venue->id, new RecordInput('venue', ['name' => 'Teatro Argentina']))->get('name'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidDependencies(): iterable
    {
        yield 'a missing parent field' => [['dependsOn' => 'district', 'dependsVia' => 'province'], '"district" is not a relation field of "venue"'];
        yield 'a parent that is no relation' => [['dependsOn' => 'name', 'dependsVia' => 'province'], '"name" is not a relation field of "venue"'];
        yield 'a via pointing elsewhere' => [['dependsOn' => 'region', 'dependsVia' => 'province'], '"province" is not a relation field of "municipality" pointing at "region"'];
        yield 'half of it' => [['dependsOn' => 'province'], 'go together'];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDependencies')]
    public function testRefusesAnInvalidDependency(array $settings, string $message): void
    {
        $this->expectException(MetadataException::class);
        $this->expectExceptionMessage($message);

        self::getContainer()->get(MetadataManagerInterface::class)->createField('venue', 'town', 'relation', 'Town', settings: ['target' => 'municipality'] + $settings);
    }
}
