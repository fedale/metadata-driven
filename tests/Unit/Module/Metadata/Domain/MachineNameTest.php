<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Metadata\Domain;

use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use App\Module\Metadata\Domain\Model\MachineName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MachineNameTest extends TestCase
{
    #[DataProvider('validNames')]
    public function testAcceptsValidNames(string $name): void
    {
        self::assertSame($name, MachineName::assert($name));
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidNames(string $name): void
    {
        $this->expectException(InvalidMetadataDefinition::class);

        MachineName::assert($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validNames(): iterable
    {
        yield 'simple' => ['pet'];
        yield 'with underscore' => ['first_name'];
        yield 'with digits' => ['address2'];
        yield 'max length' => [str_repeat('a', MachineName::MAX_LENGTH)];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'too short' => ['a'];
        yield 'too long' => [str_repeat('a', MachineName::MAX_LENGTH + 1)];
        yield 'uppercase' => ['firstName'];
        yield 'leading digit' => ['1pet'];
        yield 'leading underscore' => ['_pet'];
        yield 'trailing underscore' => ['pet_'];
        yield 'double underscore' => ['first__name'];
        yield 'dash' => ['first-name'];
        yield 'space' => ['first name'];
        yield 'quote' => ['pet"; drop table x; --'];
    }
}
