<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Metadata\Contract\Dto\KeyType;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordFilter;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Exception\RecordNotFound;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordLookupInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Objects whose key is an integer the database numbers, next to UUID ones: their tables, and
 * their records through the whole record pipeline (relations and links both ways included).
 *
 *  - team (integer key)
 *  - skill (UUID key)
 *  - player (integer key): team → team, mentor → player, skills → skill (many-to-many)
 *  - badge (UUID key): holder → player
 */
final class IntegerKeysTest extends KernelTestCase
{
    private RecordManagerInterface $records;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $object = $container->get(CreateObjectHandler::class);
        $field = $container->get(CreateFieldHandler::class);

        $object(new CreateObjectCommand('team', 'Team', 'Teams', keyType: KeyType::Integer));
        $field(new CreateFieldCommand('team', 'name', 'text', 'Name', isRequired: true));
        $object(new CreateObjectCommand('skill', 'Skill', 'Skills'));
        $field(new CreateFieldCommand('skill', 'name', 'text', 'Name', isRequired: true));
        $object(new CreateObjectCommand('player', 'Player', 'Players', keyType: KeyType::Integer));
        $field(new CreateFieldCommand('player', 'name', 'text', 'Name', isRequired: true));
        $field(new CreateFieldCommand('player', 'team', 'relation', 'Team', settings: ['target' => 'team', 'inverseName' => 'players', 'inverseLabel' => 'Players']));
        $field(new CreateFieldCommand('player', 'mentor', 'relation', 'Mentor', settings: ['target' => 'player']));
        $field(new CreateFieldCommand('player', 'skills', 'many_to_many', 'Skills', settings: ['target' => 'skill']));
        $object(new CreateObjectCommand('badge', 'Badge', 'Badges'));
        $field(new CreateFieldCommand('badge', 'name', 'text', 'Name', isRequired: true));
        $field(new CreateFieldCommand('badge', 'holder', 'relation', 'Holder', settings: ['target' => 'player', 'onDelete' => 'cascade']));

        $this->records = $container->get(RecordManagerInterface::class);
    }

    public function testTheTablesHoldKeysOfTheTypeTheyPointAt(): void
    {
        self::assertSame(KeyType::Integer, self::getContainer()->get(MetadataRegistryInterface::class)->getObject('player')->keyType);
        self::assertSame(['id' => 'bigint', 'team' => 'bigint', 'mentor' => 'bigint'], $this->columns('c_player', ['id', 'team', 'mentor']));
        self::assertSame('YES', $this->connection()->fetchOne("SELECT is_identity FROM information_schema.columns WHERE table_schema = 'data' AND table_name = 'c_player' AND column_name = 'id'"));
        self::assertSame(['id' => 'uuid', 'holder' => 'bigint'], $this->columns('c_badge', ['id', 'holder']));
        // From an integer record to UUID ones.
        self::assertSame(['record_id' => 'bigint', 'target_id' => 'uuid'], $this->columns('l_player__skills', ['record_id', 'target_id']));
    }

    public function testTheDatabaseNumbersTheRecords(): void
    {
        $red = $this->records->create(new RecordInput('team', ['name' => 'Red']));
        $blue = $this->records->create(new RecordInput('team', ['name' => 'Blue']));

        self::assertMatchesRegularExpression('/^\d+$/', $red->id);
        self::assertSame((string) ((int) $red->id + 1), $blue->id);
        self::assertSame('Red', $this->finder()->find('team', $red->id)?->get('name'));
        self::assertSame('Red', $this->finder()->find('team', '0'.$red->id)?->get('name'));
        self::assertSame([$blue->id, $red->id], array_map(static fn ($record): string => $record->id, $this->finder()->latest('team')));

        $this->records->update($red->id, new RecordInput('team', ['name' => 'Crimson']));
        self::assertSame('Crimson', $this->finder()->find('team', $red->id)?->get('name'));
        $this->records->delete('team', $red->id);
        self::assertNull($this->finder()->find('team', $red->id));
    }

    public function testAnIdOfAnotherTypeIsNoRecord(): void
    {
        $red = $this->records->create(new RecordInput('team', ['name' => 'Red']));

        // Refused before any query: a wrong id would fail it, and the transaction with it.
        self::assertNull($this->finder()->find('team', 'red'));
        self::assertNull($this->finder()->find('team', '0190a5e2-4c8b-7d3e-9f10-2b3c4d5e6f70'));
        self::assertNull($this->finder()->find('skill', $red->id));
        self::assertSame([], $this->finder()->referencing('team', 'red'));
        self::assertSame([], $this->finder()->findReferencing('player', 'team', 'red'));
        foreach ([
            fn () => $this->records->update('red', new RecordInput('team', ['name' => 'Blue'])),
            fn () => $this->records->delete('team', 'red'),
        ] as $call) {
            try {
                $call();
                self::fail('An id that is no integer names no record.');
            } catch (RecordNotFound) {
            }
        }
        self::assertSame('Red', $this->finder()->find('team', $red->id)?->get('name'));
    }

    public function testRelationsAndLinksHoldTheKeysOfTheirTargets(): void
    {
        $red = $this->records->create(new RecordInput('team', ['name' => 'Red']));
        $speed = $this->records->create(new RecordInput('skill', ['name' => 'Speed']));
        $ada = $this->records->create(new RecordInput('player', ['name' => 'Ada', 'team' => (int) $red->id, 'skills' => [strtoupper($speed->id)]]));
        $bea = $this->records->create(new RecordInput('player', ['name' => 'Bea', 'team' => $red->id, 'mentor' => ' '.$ada->id]));
        $badge = $this->records->create(new RecordInput('badge', ['name' => 'Gold', 'holder' => $ada->id]));

        // Always the text of the key, whatever was given.
        self::assertSame($red->id, $ada->get('team'));
        self::assertSame([$speed->id], $ada->get('skills'));
        self::assertSame($ada->id, $bea->get('mentor'));
        self::assertSame($ada->id, $this->finder()->find('badge', $badge->id)?->get('holder'));
        self::assertSame([$speed->id], $this->finder()->find('player', $ada->id)?->get('skills'));

        self::assertSame(['Ada', 'Bea'], array_map(static fn ($record): mixed => $record->get('name'), $this->finder()->findReferencing('player', 'team', $red->id)));
        $groups = $this->finder()->referencing('team', $red->id);
        self::assertCount(1, $groups);
        self::assertSame(2, $groups[0]->total);
        self::assertSame([$ada->id => 'Ada'], self::getContainer()->get(RecordLookupInterface::class)->labels('player', [$ada->id, 'nope']));
        self::assertSame(['Bea'], array_map(static fn ($label): string => $label->label, self::getContainer()->get(RecordLookupInterface::class)->search('player', '', 20, ['mentor' => $ada->id])));
        self::assertSame([], self::getContainer()->get(RecordLookupInterface::class)->search('player', '', 20, ['mentor' => 'nope']));
        self::assertSame(['Bea'], array_map(static fn ($record): mixed => $record->get('name'), $this->finder()->search('player', [new RecordFilter('mentor', 'eq', $ada->id)])->records));
        self::assertSame(['Ada'], array_map(static fn ($record): mixed => $record->get('name'), $this->finder()->search('player', [new RecordFilter('id', 'in', [$ada->id])])->records));

        // The delete rules follow the keys: a team deleted empties its players' field, a player
        // deleted takes their badges.
        $this->records->delete('team', $red->id);
        self::assertNull($this->finder()->find('player', $ada->id)?->get('team'));
        $this->records->delete('player', $ada->id);
        self::assertNull($this->finder()->find('badge', $badge->id));
        self::assertNull($this->finder()->find('player', $bea->id)?->get('mentor'));
    }

    public function testAReferenceThatIsNoKeyOfTheTargetIsRefused(): void
    {
        $speed = $this->records->create(new RecordInput('skill', ['name' => 'Speed']));

        self::assertSame(['team' => ['This value is not a valid record reference.']], $this->errors(['name' => 'Ada', 'team' => $speed->id]));
        self::assertSame(['team' => ['The selected record does not exist.']], $this->errors(['name' => 'Ada', 'team' => '999999']));
        self::assertSame(['skills' => ['This value is not a valid record reference.']], $this->errors(['name' => 'Ada', 'skills' => ['42']]));
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, list<string>>
     */
    private function errors(array $values): array
    {
        try {
            $this->records->create(new RecordInput('player', $values));
        } catch (RecordValidationFailed $e) {
            // In English: a field type's errors are messages.
            return array_map(static fn (array $messages): array => array_map(strval(...), $messages), $e->errors);
        }
        self::fail('The record was written.');
    }

    /**
     * @param list<string> $columns
     *
     * @return array<string, string> column => data type, in the order asked
     */
    private function columns(string $table, array $columns): array
    {
        $types = $this->connection()->fetchAllKeyValue(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'data' AND table_name = ?",
            [$table],
        );

        return array_combine($columns, array_map(static fn (string $column): string => \is_string($types[$column] ?? null) ? $types[$column] : 'missing', $columns));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function finder(): RecordFinderInterface
    {
        return self::getContainer()->get(RecordFinderInterface::class);
    }
}
