<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Metadata;

use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Metadata\Contract\Exception\InvalidFieldSettings;
use App\Module\Metadata\Contract\Exception\ObjectDefinitionNotFound;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs against the real PostgreSQL test database; each test is rolled back.
 */
final class MetadataLifecycleTest extends KernelTestCase
{
    private CreateObjectHandler $createObject;
    private CreateFieldHandler $createField;
    private MetadataRegistryInterface $registry;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->createObject = $container->get(CreateObjectHandler::class);
        $this->createField = $container->get(CreateFieldHandler::class);
        $this->registry = $container->get(MetadataRegistryInterface::class);
    }

    public function testCreatedDefinitionsAreExposedByTheRegistry(): void
    {
        $objectId = ($this->createObject)(new CreateObjectCommand('pet', 'Pet', 'Pets', 'paw'));
        ($this->createField)(new CreateFieldCommand('pet', 'name', 'text', 'Name', isRequired: true, settings: ['maxLength' => 80]));
        ($this->createField)(new CreateFieldCommand('pet', 'species', 'select', 'Species', settings: [
            'options' => [['value' => 'dog', 'label' => 'Dog'], ['value' => 'cat', 'label' => 'Cat']],
        ]));

        // Read back through a fresh entity manager state, as a new request would.
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $pet = $this->registry->getObject('pet');
        self::assertSame($objectId, $pet->id);
        self::assertSame('c_pet', $pet->tableName);
        self::assertSame('paw', $pet->icon);
        self::assertSame(['name', 'species'], array_keys($pet->fields));

        $name = $pet->field('name');
        self::assertSame('text', $name->type);
        self::assertTrue($name->isRequired);
        self::assertSame(['maxLength' => 80, 'multiline' => false], $name->settings);
        self::assertSame(1, $pet->field('species')->position);
    }

    public function testRegistryIsRefreshedWhenDefinitionsChange(): void
    {
        self::assertFalse($this->registry->hasObject('pet'));

        ($this->createObject)(new CreateObjectCommand('pet', 'Pet', 'Pets'));
        self::assertTrue($this->registry->hasObject('pet'));
        self::assertSame([], $this->registry->getObject('pet')->fields);

        ($this->createField)(new CreateFieldCommand('pet', 'name', 'text', 'Name'));
        self::assertTrue($this->registry->getObject('pet')->hasField('name'));
    }

    public function testRejectsDuplicateObjects(): void
    {
        ($this->createObject)(new CreateObjectCommand('pet', 'Pet', 'Pets'));

        $this->expectException(InvalidMetadataDefinition::class);

        ($this->createObject)(new CreateObjectCommand('pet', 'Pet', 'Pets'));
    }

    public function testRejectsFieldsOnUnknownObjects(): void
    {
        $this->expectException(ObjectDefinitionNotFound::class);

        ($this->createField)(new CreateFieldCommand('ghost', 'name', 'text', 'Name'));
    }

    public function testRejectsUnknownFieldTypes(): void
    {
        ($this->createObject)(new CreateObjectCommand('pet', 'Pet', 'Pets'));

        $this->expectException(InvalidMetadataDefinition::class);
        $this->expectExceptionMessage('Unknown field type "color"');

        ($this->createField)(new CreateFieldCommand('pet', 'fur', 'color', 'Fur'));
    }

    public function testRejectsInvalidSettings(): void
    {
        ($this->createObject)(new CreateObjectCommand('pet', 'Pet', 'Pets'));

        $this->expectException(InvalidFieldSettings::class);

        ($this->createField)(new CreateFieldCommand('pet', 'species', 'select', 'Species'));
    }
}
