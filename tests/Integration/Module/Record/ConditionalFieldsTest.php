<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Fields that apply only while another field holds some values, through the record pipeline.
 */
final class ConditionalFieldsTest extends KernelTestCase
{
    private MetadataManagerInterface $metadata;
    private RecordManagerInterface $records;

    protected function setUp(): void
    {
        $this->metadata = self::getContainer()->get(MetadataManagerInterface::class);
        $this->records = self::getContainer()->get(RecordManagerInterface::class);

        $this->metadata->createObject('qualification', 'Qualification', 'Qualifications');
        $this->metadata->createField('qualification', 'kind', 'select', 'Kind', true, settings: ['options' => [
            ['value' => 'education', 'label' => 'Education'], ['value' => 'course', 'label' => 'Course'], ['value' => 'language', 'label' => 'Language'],
        ]]);
        $this->metadata->createField('qualification', 'title', 'text', 'Title', true);
        $this->metadata->createField('qualification', 'hours', 'number', 'Hours', true);
        $this->metadata->createField('qualification', 'level', 'select', 'Level', settings: ['options' => [['value' => 'b2', 'label' => 'B2'], ['value' => 'c1', 'label' => 'C1']]]);
        $this->metadata->createField('qualification', 'online', 'boolean', 'Online');
        $this->metadata->configureFieldCondition('qualification', 'hours', 'kind', ['course']);
        $this->metadata->configureFieldCondition('qualification', 'level', 'kind', ['language']);
    }

    public function testTheConditionIsPartOfTheMetadata(): void
    {
        $hours = self::getContainer()->get(MetadataRegistryInterface::class)->getObject('qualification')->field('hours');

        self::assertSame('kind', $hours->condition?->field);
        self::assertSame(['course'], $hours->condition->values);
    }

    public function testARequiredFieldIsRequiredOnlyWhenItApplies(): void
    {
        try {
            $this->records->create(new RecordInput('qualification', ['kind' => 'course', 'title' => 'Symfony']));
            self::fail('A course needs its hours.');
        } catch (RecordValidationFailed $e) {
            self::assertSame(['hours' => ['This value is required.']], $e->errors);
        }

        $language = $this->records->create(new RecordInput('qualification', ['kind' => 'language', 'title' => 'English', 'level' => 'c1']));
        self::assertNull($language->get('hours'));
        self::assertSame('c1', $language->get('level'));
    }

    public function testAFieldThatDoesNotApplyIsEmptied(): void
    {
        $language = $this->records->create(new RecordInput('qualification', ['kind' => 'language', 'title' => 'English', 'hours' => 40, 'level' => 'b2']));
        self::assertNull($language->get('hours'));

        // The course becomes a language: its hours go, the level stays empty until given.
        $course = $this->records->create(new RecordInput('qualification', ['kind' => 'course', 'title' => 'Symfony', 'hours' => 24]));
        $updated = $this->records->update($course->id, new RecordInput('qualification', ['kind' => 'language', 'level' => 'b2']));
        self::assertNull($updated->get('hours'));
        self::assertSame('b2', $updated->get('level'));
    }

    public function testAnUpdateLeavingTheDecidingFieldOutReadsItFromTheRecord(): void
    {
        $course = $this->records->create(new RecordInput('qualification', ['kind' => 'course', 'title' => 'Symfony', 'hours' => 24]));

        // The kind is not in the input: the record's kind still decides.
        $renamed = $this->records->update($course->id, new RecordInput('qualification', ['title' => 'Symfony 8']));
        self::assertSame(24, $renamed->get('hours'));
        self::assertSame('course', $renamed->get('kind'));

        $this->expectException(RecordValidationFailed::class);
        $this->records->update($course->id, new RecordInput('qualification', ['hours' => null]));
    }

    /**
     * @return iterable<string, array{string, string|null, list<string>, string}>
     */
    public static function invalidConditions(): iterable
    {
        yield 'a text cannot decide' => ['hours', 'title', ['Symfony'], 'only a choice or a yes/no field can'];
        yield 'a value the choice never holds' => ['hours', 'kind', ['seminar'], 'never holds seminar'];
        yield 'no value' => ['title', 'online', [], 'at least one value'];
        yield 'itself' => ['kind', 'kind', ['course'], 'cannot decide for itself'];
        yield 'a decided field cannot decide' => ['title', 'level', ['b2'], 'conditions do not chain'];
        yield 'a deciding field cannot be decided' => ['kind', 'online', ['true'], 'conditions do not chain'];
    }

    /**
     * @param list<string> $values
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidConditions')]
    public function testRefusesAnInvalidCondition(string $field, ?string $when, array $values, string $message): void
    {
        $this->expectException(MetadataException::class);
        $this->expectExceptionMessage($message);

        $this->metadata->configureFieldCondition('qualification', $field, $when, $values);
    }

    public function testAYesNoFieldDecidesAndAConditionCanBeRemoved(): void
    {
        $this->metadata->configureFieldCondition('qualification', 'title', 'online', ['false']);
        $online = $this->records->create(new RecordInput('qualification', ['kind' => 'education', 'online' => true]));
        self::assertNull($online->get('title'));

        $this->metadata->configureFieldCondition('qualification', 'title', null);
        $this->expectException(RecordValidationFailed::class);
        $this->records->create(new RecordInput('qualification', ['kind' => 'education', 'online' => true]));
    }
}
