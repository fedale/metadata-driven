<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Metadata;

use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Domain\Repository\TranslationRepository;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use App\Tests\Support\CreatesPetObject;
use App\Tests\Support\CreatesRegistrationForm;
use Doctrine\DBAL\Connection;
use Fedale\RbacBundle\Contract\ItemStorageInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Archiving takes an object or a field out of the application and keeps its data; restoring
 * brings it back as it was. Only what nothing else uses can be archived, and only what is
 * archived can be purged, its data with it.
 */
final class ArchiveTest extends KernelTestCase
{
    use CreatesPetObject;
    use CreatesRegistrationForm;

    private MetadataManagerInterface $manager;
    private MetadataRegistryInterface $metadata;

    protected function setUp(): void
    {
        $this->manager = self::getContainer()->get(MetadataManagerInterface::class);
        $this->metadata = self::getContainer()->get(MetadataRegistryInterface::class);
    }

    public function testAnArchivedFieldKeepsItsColumnAndComesBackWithItsValues(): void
    {
        self::createPetObject(self::getContainer());
        $records = self::getContainer()->get(RecordManagerInterface::class);
        $rex = $records->create(new RecordInput('pet', ['name' => 'Rex', 'weight' => 12.5]))->id;
        $this->manager->configureSearch('pet', null, ['name', 'chip_code']);
        $this->manager->configureLabel('pet', 'chip_code');

        $this->manager->archiveField('pet', 'chip_code');

        $pet = $this->metadata->getObject('pet');
        self::assertFalse($pet->hasField('chip_code'));
        self::assertArrayHasKey('chip_code', $pet->archivedFields);
        // It no longer names nor finds the records.
        self::assertNull($pet->labelField);
        self::assertSame(['name'], $pet->searchFields);
        // The column, and its unique index, stay: the table matches the definition.
        self::assertArrayHasKey('chip_code', $this->columns('c_pet'));
        $plan = self::getContainer()->get(SchemaSynchronizerInterface::class)->plan('pet', allowDestructive: true);
        self::assertSame([], $plan->statements);
        // The records are written without it; its name stays taken.
        $records->create(new RecordInput('pet', ['name' => 'Fido']));
        $this->assertRefused(fn () => $this->manager->createField('pet', 'chip_code', 'text', 'Chip'), 'archived field named "chip_code"');

        $this->manager->archiveField('pet', 'weight');
        $this->manager->restoreField('pet', 'weight');
        self::assertEquals(12.5, self::getContainer()->get(RecordFinderInterface::class)->find('pet', $rex)?->get('weight'));
    }

    public function testWhatIsUsedCannotBeArchived(): void
    {
        self::createRegistrationForm(self::getContainer());
        $this->manager->configureFieldCondition('account', 'lastname', 'active', ['true']);

        $this->assertRefused(fn () => $this->manager->archiveField('account', 'active'), 'Field "account.active" cannot be archived, it is still used: field "lastname" is shown depending on it; composite form "registration", (root): fields.');
        $this->assertRefused(fn () => $this->manager->archiveObject('profile'), 'Object "profile" cannot be archived, it is still used: relation field "phone.profile" points at it; composite form "registration" (profile).');
        self::assertTrue($this->metadata->getObject('account')->hasField('active'));
    }

    public function testAnArchivedObjectIsOutOfTheApplicationUntilRestored(): void
    {
        self::createPetObject(self::getContainer());
        $rex = self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('pet', ['name' => 'Rex']))->id;
        $this->manager->createObject('toy', 'Toy', 'Toys');
        $this->manager->createField('toy', 'pet', 'relation', 'Pet', settings: ['target' => 'pet']);

        $this->assertRefused(fn () => $this->manager->archiveObject('pet'), 'relation field "toy.pet" points at it');
        $this->manager->archiveObject('toy');
        $this->manager->archiveObject('pet');

        self::assertFalse($this->metadata->hasObject('pet'));
        self::assertSame(['pet', 'toy'], array_keys($this->metadata->archivedObjects()));
        self::assertArrayHasKey('name', $this->columns('c_pet'));
        $this->assertRefused(fn () => $this->manager->createObject('pet', 'Pet', 'Pets'), 'archived object is named "pet"');
        // A relation to an archived object waits for its target.
        $this->assertRefused(fn () => $this->manager->restoreObject('toy'), 'relation field "pet" points at object "pet", archived too');

        $this->manager->restoreObject('pet');
        $this->manager->restoreObject('toy');
        self::assertSame('Rex', self::getContainer()->get(RecordFinderInterface::class)->find('pet', $rex)?->get('name'));
    }

    public function testPurgingForgetsAnArchivedFieldOrObjectWithItsData(): void
    {
        self::createPetObject(self::getContainer());
        $this->manager->translate('it', 'field.pet.age', 'Età');
        $this->manager->translate('it', 'object.pet.plural', 'Animali');
        $this->assertRefused(fn () => $this->manager->purgeField('pet', 'age'), 'no archived field "age"');

        $this->manager->archiveField('pet', 'age');
        $this->manager->purgeField('pet', 'age');

        self::assertArrayNotHasKey('age', $this->metadata->getObject('pet')->archivedFields);
        self::assertArrayNotHasKey('age', $this->columns('c_pet'));
        self::assertArrayNotHasKey('field.pet.age', self::getContainer()->get(TranslationRepository::class)->textsOf('it'));
        // The name is free again.
        $this->manager->createField('pet', 'age', 'number', 'Age');

        $this->manager->archiveObject('pet');
        $this->manager->purgeObject('pet');

        self::assertSame([], $this->metadata->archivedObjects());
        self::assertFalse((bool) $this->connection()->fetchOne("SELECT to_regclass('data.c_pet') IS NOT NULL"));
        self::assertNull(self::getContainer()->get(ItemStorageInterface::class)->getItem('record.pet.read'));
        self::assertArrayNotHasKey('object.pet.plural', self::getContainer()->get(TranslationRepository::class)->textsOf('it'));
        $this->manager->createObject('pet', 'Pet', 'Pets');
        self::assertTrue($this->metadata->hasObject('pet'));
    }

    public function testTheCommandsArchiveAndPurgeAskingForTheNameBack(): void
    {
        self::createPetObject(self::getContainer());
        $application = new Application(self::bootKernel());
        // The commands run in the new kernel: read its registry.
        $this->metadata = self::getContainer()->get(MetadataRegistryInterface::class);
        $archive = new CommandTester($application->find('metadata:archive'));
        self::assertSame(0, $archive->execute(['object' => 'pet', '--field' => 'age']));
        self::assertStringContainsString('Field "pet.age" is archived', $archive->getDisplay());

        $purge = new CommandTester($application->find('metadata:purge'));
        self::assertSame(2, $purge->execute(['object' => 'pet', '--field' => 'age'], ['interactive' => false]));
        self::assertStringContainsString('pass --force', $purge->getDisplay());
        $purge->setInputs(['pet.agee']);
        self::assertSame(1, $purge->execute(['object' => 'pet', '--field' => 'age']));
        self::assertArrayHasKey('age', $this->metadata->getObject('pet')->archivedFields);
        $purge->setInputs(['pet.age']);
        self::assertSame(0, $purge->execute(['object' => 'pet', '--field' => 'age']));
        self::assertArrayNotHasKey('age', $this->metadata->getObject('pet')->archivedFields);

        $rename = new CommandTester($application->find('metadata:rename'));
        self::assertSame(0, $rename->execute(['object' => 'pet', 'new-name' => 'animal']));
        self::assertTrue($this->metadata->hasObject('animal'));
    }

    private function assertRefused(callable $change, string $message): void
    {
        try {
            $change();
            self::fail('The change must be refused.');
        } catch (MetadataException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    /**
     * @return array<string, true>
     */
    private function columns(string $table): array
    {
        /** @var list<string> $names */
        $names = $this->connection()->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = 'data' AND table_name = ?", [$table]);

        return array_fill_keys($names, true);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
