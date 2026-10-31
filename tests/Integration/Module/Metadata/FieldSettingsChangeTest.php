<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Metadata;

use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The settings of an existing field change, within what the column, the records and the
 * conditions allow.
 */
final class FieldSettingsChangeTest extends KernelTestCase
{
    private MetadataManagerInterface $metadata;
    private MetadataRegistryInterface $registry;

    protected function setUp(): void
    {
        $this->metadata = self::getContainer()->get(MetadataManagerInterface::class);
        $this->registry = self::getContainer()->get(MetadataRegistryInterface::class);

        $this->metadata->createObject('phone', 'Phone', 'Phones');
        $this->metadata->createField('phone', 'prefix', 'select', 'Prefix', settings: ['options' => [['value' => '+39', 'label' => '+39 IT'], ['value' => '+41', 'label' => '+41 CH']]]);
        $this->metadata->createField('phone', 'number', 'text', 'Number', settings: ['maxLength' => 20, 'pattern' => '\d+']);
        $this->metadata->createField('phone', 'pin', 'password', 'PIN');
        $this->metadata->createField('phone', 'note', 'text', 'Note');
        $this->metadata->configureFieldCondition('phone', 'note', 'prefix', ['+41']);
    }

    public function testChangesOnlyTheSettingsGiven(): void
    {
        $this->metadata->configureFieldSettings('phone', 'pin', ['strengthMeter' => true, 'revealToggle' => true]);

        $pin = $this->registry->getObject('phone')->field('pin')->settings;
        self::assertTrue($pin['strengthMeter']);
        self::assertTrue($pin['revealToggle']);
        self::assertSame(8, $pin['minLength']);
    }

    public function testAClearedSettingTakesEffect(): void
    {
        $records = self::getContainer()->get(RecordManagerInterface::class);
        try {
            $records->create(new RecordInput('phone', ['number' => '+39 02']));
            self::fail('The pattern refuses it.');
        } catch (RecordValidationFailed) {
        }

        $this->metadata->configureFieldSettings('phone', 'number', ['pattern' => null]);
        self::assertSame('+39 02', $records->create(new RecordInput('phone', ['number' => '+39 02']))->get('number'));
    }

    public function testOptionsCanBeAddedRelabelledAndRemovedWhenUnused(): void
    {
        $this->metadata->configureFieldSettings('phone', 'prefix', ['options' => [
            ['value' => '+39', 'label' => 'Italia'], ['value' => '+41', 'label' => '+41 CH'], ['value' => '+33', 'label' => '+33 FR'],
        ]]);
        self::assertSame(['+39', '+41', '+33'], array_column((array) $this->registry->getObject('phone')->field('prefix')->settings['options'], 'value'));

        $this->metadata->configureFieldSettings('phone', 'prefix', ['options' => [['value' => '+39', 'label' => 'Italia'], ['value' => '+41', 'label' => '+41 CH']]]);
        self::assertCount(2, (array) $this->registry->getObject('phone')->field('prefix')->settings['options']);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function refusedChanges(): iterable
    {
        yield 'a setting shaping the column' => ['number', ['maxLength' => 30], 'Setting "maxLength" of field "number" cannot change'];
        yield 'an option a condition names' => ['prefix', ['options' => [['value' => '+39', 'label' => '+39 IT']]], 'the condition of "note" names it'];
        yield 'an invalid setting' => ['prefix', ['display' => 'grid'], 'dropdown, radio'];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedChanges')]
    public function testRefusesAChange(string $field, array $settings, string $message): void
    {
        $this->expectException(MetadataException::class);
        $this->expectExceptionMessage($message);

        $this->metadata->configureFieldSettings('phone', $field, $settings);
    }

    public function testAnOptionHeldByRecordsCannotBeRemoved(): void
    {
        $this->metadata->configureFieldCondition('phone', 'note', null);
        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('phone', ['prefix' => '+41', 'number' => '044']));

        $this->expectException(MetadataException::class);
        $this->expectExceptionMessage('Option "+41" of field "prefix" cannot be removed: 1 record(s) hold it.');
        $this->metadata->configureFieldSettings('phone', 'prefix', ['options' => [['value' => '+39', 'label' => '+39 IT']]]);
    }
}
