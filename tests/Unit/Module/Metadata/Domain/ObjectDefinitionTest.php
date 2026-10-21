<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Metadata\Domain;

use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use App\Module\Metadata\Domain\Model\ObjectDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ObjectDefinitionTest extends TestCase
{
    public function testDerivesTableNameFromName(): void
    {
        $object = $this->createPet();

        self::assertSame('pet', $object->getName());
        self::assertSame('c_pet', $object->getTableName());
        self::assertTrue($object->isActive());
        self::assertFalse($object->isSystem());
    }

    public function testRejectsEmptyLabels(): void
    {
        $this->expectException(InvalidMetadataDefinition::class);

        new ObjectDefinition(Uuid::v7(), 'pet', '  ', 'Pets');
    }

    public function testAddsFieldsInPositionOrder(): void
    {
        $object = $this->createPet();
        $object->addField(Uuid::v7(), 'name', 'text', 'Name', true, false, []);
        $object->addField(Uuid::v7(), 'birth_date', 'date', 'Birth date', false, false, []);

        $fields = $object->getFields();
        self::assertSame(['name', 'birth_date'], array_map(static fn ($field) => $field->getName(), $fields));
        self::assertSame([0, 1], array_map(static fn ($field) => $field->getPosition(), $fields));
        self::assertSame('birth_date', $fields[1]->getColumnName());
    }

    public function testRejectsDuplicateFieldNames(): void
    {
        $object = $this->createPet();
        $object->addField(Uuid::v7(), 'name', 'text', 'Name', true, false, []);

        $this->expectException(InvalidMetadataDefinition::class);
        $this->expectExceptionMessage('already has a field named "name"');

        $object->addField(Uuid::v7(), 'name', 'text', 'Other name', false, false, []);
    }

    public function testRejectsSystemColumnNames(): void
    {
        $this->expectException(InvalidMetadataDefinition::class);
        $this->expectExceptionMessage('reserved');

        $this->createPet()->addField(Uuid::v7(), 'created_at', 'date', 'Created', false, false, []);
    }

    public function testRejectsInvalidFieldNames(): void
    {
        $this->expectException(InvalidMetadataDefinition::class);

        $this->createPet()->addField(Uuid::v7(), 'Bad Name', 'text', 'Bad', false, false, []);
    }

    private function createPet(): ObjectDefinition
    {
        return new ObjectDefinition(Uuid::v7(), 'pet', 'Pet', 'Pets');
    }
}
