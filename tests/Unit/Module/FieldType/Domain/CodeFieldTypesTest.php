<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\FieldType\Domain;

use App\Module\FieldType\Contract\Dto\ColumnSpec;
use App\Module\FieldType\Contract\Dto\ColumnType;
use App\Module\FieldType\Contract\Dto\Widget;
use App\Module\FieldType\Contract\Dto\WidgetKind;
use App\Module\FieldType\Domain\Type\AbstractCodeFieldType;
use App\Module\FieldType\Domain\Type\CountryFieldType;
use App\Module\FieldType\Domain\Type\CurrencyFieldType;
use App\Module\FieldType\Domain\Type\LanguageFieldType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * The country, language and currency types: an ISO code, in its case, from symfony/intl's lists.
 */
final class CodeFieldTypesTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractCodeFieldType, WidgetKind, int, string, string, string}>
     */
    public static function types(): iterable
    {
        yield 'country' => [new CountryFieldType(), WidgetKind::Country, 2, ' it ', 'IT', 'XX'];
        yield 'language' => [new LanguageFieldType(), WidgetKind::Language, 3, 'FUR', 'fur', 'xx'];
        yield 'currency' => [new CurrencyFieldType(), WidgetKind::Currency, 3, 'eur', 'EUR', 'XXX'];
    }

    #[DataProvider('types')]
    public function testDescribesItsColumnAndWidget(AbstractCodeFieldType $type, WidgetKind $kind, int $length, string $raw, string $code, string $unknown): void
    {
        unset($raw, $code, $unknown);
        self::assertEquals(new ColumnSpec(ColumnType::String, length: $length), $type->columnSpec($type->defaultSettings()));
        self::assertEquals(new Widget($kind, ['preferred' => []]), $type->widget($type->defaultSettings()));
        self::assertTrue($type->isSortable());
        self::assertTrue($type->supportsUnique());
    }

    #[DataProvider('types')]
    public function testACodeTakesItsCaseAndAnUnknownOneIsRefused(AbstractCodeFieldType $type, WidgetKind $kind, int $length, string $raw, string $code, string $unknown): void
    {
        $validator = Validation::createValidator();

        self::assertSame($code, $type->denormalize($raw, $type->defaultSettings()));
        self::assertCount(0, $validator->validate($code, $type->constraints($type->defaultSettings())));
        self::assertNull($type->denormalize('', $type->defaultSettings()));
        self::assertCount(1, $validator->validate($type->denormalize($unknown, $type->defaultSettings()), $type->constraints($type->defaultSettings())));
    }

    #[DataProvider('types')]
    public function testItsSettingsNameKnownCodes(AbstractCodeFieldType $type, WidgetKind $kind, int $length, string $raw, string $code, string $unknown): void
    {
        self::assertSame([], $type->validateSettings(['preferred' => [$code], 'default' => $code]));
        self::assertSame($code, $type->defaultValue(['default' => $code] + $type->defaultSettings()));
        self::assertEquals(new Widget($kind, ['preferred' => [$code]]), $type->widget(['preferred' => [$code]] + $type->defaultSettings()));

        $errors = implode(' ', $type->validateSettings(['preferred' => [$code, $code, $unknown], 'default' => $unknown]));
        self::assertStringContainsString(\sprintf('"%s" is listed twice', $code), $errors);
        self::assertStringContainsString(\sprintf('Setting "preferred": "%s" is no code of the list', $unknown), $errors);
        self::assertStringContainsString(\sprintf('Setting "default": "%s" is no code of the list', $unknown), $errors);
        // Written as the list writes it: "it" is no country code, "IT" is.
        self::assertNotSame([], (new CountryFieldType())->validateSettings(['default' => 'it']));
    }
}
