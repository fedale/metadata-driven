<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\FieldType\Application;

use App\Module\FieldType\Application\IntlIsoCodes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IntlIsoCodesTest extends TestCase
{
    private string $locale;

    protected function setUp(): void
    {
        $this->locale = \Locale::getDefault();
        \Locale::setDefault('it');
    }

    protected function tearDown(): void
    {
        \Locale::setDefault($this->locale);
    }

    #[DataProvider('values')]
    public function testReadsACodeOrAName(string $type, string $value, ?string $code): void
    {
        self::assertSame($code, (new IntlIsoCodes())->code($type, $value));
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function values(): iterable
    {
        yield 'a code in any case' => ['country', ' it ', 'IT'];
        yield 'a name in the current language' => ['country', 'regno unito', 'GB'];
        yield 'a name in English' => ['country', 'United Kingdom', 'GB'];
        yield 'a language by name' => ['language', 'Friulano', 'fur'];
        yield 'a currency by name' => ['currency', 'euro', 'EUR'];
        yield 'nothing matches' => ['country', 'Atlantide', null];
        yield 'a type taking no code' => ['text', 'Italia', null];
    }
}
