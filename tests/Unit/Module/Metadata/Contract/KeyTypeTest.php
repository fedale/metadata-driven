<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Metadata\Contract;

use App\Module\Metadata\Contract\Dto\KeyType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The ids of each key type: which are valid, and the one text each is written as.
 */
final class KeyTypeTest extends TestCase
{
    #[DataProvider('ids')]
    public function testNormalizesTheIdsOfItsType(KeyType $type, mixed $id, ?string $expected): void
    {
        self::assertSame($expected, $type->normalize($id));
        self::assertSame(null !== $expected, $type->isValid($id));
    }

    /**
     * @return iterable<string, array{KeyType, mixed, string|null}>
     */
    public static function ids(): iterable
    {
        yield 'uuid' => [KeyType::Uuid, '0190a5e2-4c8b-7d3e-9f10-2b3c4d5e6f70', '0190a5e2-4c8b-7d3e-9f10-2b3c4d5e6f70'];
        yield 'uuid in capitals, spaced' => [KeyType::Uuid, ' 0190A5E2-4C8B-7D3E-9F10-2B3C4D5E6F70 ', '0190a5e2-4c8b-7d3e-9f10-2b3c4d5e6f70'];
        yield 'uuid: an integer is none' => [KeyType::Uuid, '42', null];
        yield 'uuid: an int is none' => [KeyType::Uuid, 42, null];
        yield 'uuid: no text' => [KeyType::Uuid, 'nope', null];
        yield 'integer' => [KeyType::Integer, '42', '42'];
        yield 'integer as an int' => [KeyType::Integer, 42, '42'];
        yield 'integer, leading zeros and spaces' => [KeyType::Integer, ' 007 ', '7'];
        yield 'integer, negative' => [KeyType::Integer, '-3', '-3'];
        yield 'integer, the largest bigint' => [KeyType::Integer, '9223372036854775807', '9223372036854775807'];
        yield 'integer, past a bigint' => [KeyType::Integer, '9223372036854775808', null];
        yield 'integer: a decimal is none' => [KeyType::Integer, '4.2', null];
        yield 'integer: a sign "+" is none' => [KeyType::Integer, '+4', null];
        yield 'integer: a uuid is none' => [KeyType::Integer, '0190a5e2-4c8b-7d3e-9f10-2b3c4d5e6f70', null];
        yield 'integer: empty' => [KeyType::Integer, '', null];
        yield 'integer: no scalar' => [KeyType::Integer, ['1'], null];
    }

    public function testTellsAnIdOfAnyType(): void
    {
        self::assertSame('0190a5e2-4c8b-7d3e-9f10-2b3c4d5e6f70', KeyType::canonical('0190A5E2-4C8B-7D3E-9F10-2B3C4D5E6F70'));
        self::assertSame('12', KeyType::canonical('012'));
        self::assertNull(KeyType::canonical('x12'));
    }

    public function testReadsTheIdsTheDatabaseReturns(): void
    {
        self::assertSame('12', KeyType::fromDatabase(12));
        self::assertSame('0190a5e2-4c8b-7d3e-9f10-2b3c4d5e6f70', KeyType::fromDatabase('0190a5e2-4c8b-7d3e-9f10-2b3c4d5e6f70'));
        self::assertNull(KeyType::fromDatabase(null));
    }

    public function testTheRouteRequirementTakesBothTypesAndNothingElse(): void
    {
        $matches = static fn (string $id): bool => 1 === preg_match('#^(?:'.KeyType::ROUTE_REQUIREMENT.')$#', $id);

        self::assertTrue($matches('0190a5e2-4c8b-7d3e-9f10-2b3c4d5e6f70'));
        self::assertTrue($matches('42'));
        self::assertFalse($matches('new'));
        self::assertFalse($matches('42/edit'));
    }
}
