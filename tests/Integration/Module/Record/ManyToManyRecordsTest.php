<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\CompositeRecordManagerInterface;
use App\Module\Record\Contract\Dto\CompositeNodeInput;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\Exception\RecordDeleteRestricted;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\CreatesTaggedContacts;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The links of a many-to-many field through the record pipeline: written with the record, read
 * back with it, validated, and kept consistent when a linked record is deleted.
 */
final class ManyToManyRecordsTest extends KernelTestCase
{
    use CreatesTaggedContacts;

    private RecordManagerInterface $records;

    protected function setUp(): void
    {
        self::createTaggedContacts(self::getContainer());
        $this->records = self::getContainer()->get(RecordManagerInterface::class);
    }

    public function testLinksAreWrittenAndReadWithTheRecord(): void
    {
        $vip = $this->tag('VIP');
        $press = $this->tag('Press');

        $anna = $this->records->create(new RecordInput('contact', ['full_name' => 'Anna Rossi', 'tags' => [$press, strtoupper($vip), $press]]));

        // Lower case, once each; the order is not kept (oldest link first, then by id).
        self::assertEqualsCanonicalizing([$press, $vip], $anna->get('tags'));
        self::assertEqualsCanonicalizing([$press, $vip], $this->finder()->find('contact', $anna->id)?->get('tags'));
        $latest = $this->finder()->latest('contact');
        self::assertEqualsCanonicalizing([$press, $vip], $latest[0]->get('tags'));

        $bruno = $this->records->create(new RecordInput('contact', ['full_name' => 'Bruno Bianchi']));
        self::assertSame([], $bruno->get('tags'));
    }

    public function testAnUpdateReplacesTheLinksOrLeavesThemAlone(): void
    {
        $vip = $this->tag('VIP');
        $press = $this->tag('Press');
        $partner = $this->tag('Partner');
        $anna = $this->records->create(new RecordInput('contact', ['full_name' => 'Anna Rossi', 'tags' => [$vip, $press]]));
        $linkedAt = $this->linkDate($anna->id, $vip);

        // Left out: untouched.
        $updated = $this->records->update($anna->id, new RecordInput('contact', ['full_name' => 'Anna Verdi']));
        self::assertEqualsCanonicalizing([$vip, $press], $updated->get('tags'));

        // A list replaces the set; a link kept keeps its date.
        $updated = $this->records->update($anna->id, new RecordInput('contact', ['tags' => [$partner, $vip]]));
        self::assertEqualsCanonicalizing([$vip, $partner], $updated->get('tags'));
        self::assertSame($linkedAt, $this->linkDate($anna->id, $vip));
        // Changing the links alone marks the record as changed.
        self::assertGreaterThanOrEqual($anna->updatedAt, $updated->updatedAt);

        // An empty list, or null, removes them all.
        self::assertSame([], $this->records->update($anna->id, new RecordInput('contact', ['tags' => []]))->get('tags'));
        $this->records->update($anna->id, new RecordInput('contact', ['tags' => [$vip]]));
        self::assertSame([], $this->records->update($anna->id, new RecordInput('contact', ['tags' => null]))->get('tags'));
    }

    public function testLinksAreValidated(): void
    {
        $vip = $this->tag('VIP');
        $deleted = $this->tag('Old');
        $this->records->delete('tag', $deleted);

        self::assertSame(['tags' => ['This value is not a valid record reference.']], $this->errors(['full_name' => 'Anna', 'tags' => ['42']]));
        self::assertSame(['tags' => ['One of the selected records does not exist.']], $this->errors(['full_name' => 'Anna', 'tags' => [$vip, $deleted]]));
        self::assertSame(['tags' => ['Choose at most 3 records.']], $this->errors(['full_name' => 'Anna', 'tags' => [$vip, $this->tag('A'), $this->tag('B'), $this->tag('C')]]));

        self::getContainer()->get(MetadataManagerInterface::class)->createField('contact', 'roles', 'many_to_many', 'Roles', required: true, settings: ['target' => 'tag']);
        self::assertSame(['roles' => ['This value is required.']], $this->errors(['full_name' => 'Anna', 'roles' => []]));
        self::assertSame([], $this->records->check(new RecordInput('contact', ['full_name' => 'Anna', 'roles' => [$vip]])));
    }

    public function testDeletingALinkedRecordUnlinksItOrIsRefused(): void
    {
        $vip = $this->tag('VIP');
        $press = $this->tag('Press');
        $anna = $this->records->create(new RecordInput('contact', ['full_name' => 'Anna Rossi', 'tags' => [$vip, $press]]));

        // "unlink": the contact stays, without the link.
        $this->records->delete('tag', $vip);
        $after = $this->finder()->find('contact', $anna->id);
        self::assertNotNull($after);
        self::assertSame([$press], $after->get('tags'));
        self::assertGreaterThanOrEqual($anna->updatedAt, $after->updatedAt);
        self::assertSame(0, $this->linkCount($vip));

        // "restrict": refused while a live contact is linked to it.
        $manager = self::getContainer()->get(MetadataManagerInterface::class);
        $manager->createObject('skill', 'Skill', 'Skills');
        $manager->createField('skill', 'name', 'text', 'Name');
        $manager->createField('contact', 'skills', 'many_to_many', 'Skills', settings: ['target' => 'skill', 'onDelete' => 'restrict']);
        $php = $this->records->create(new RecordInput('skill', ['name' => 'PHP']))->id;
        $this->records->update($anna->id, new RecordInput('contact', ['skills' => [$php]]));
        try {
            $this->records->delete('skill', $php);
            self::fail('The deletion must be refused.');
        } catch (RecordDeleteRestricted $e) {
            self::assertSame('This record cannot be deleted: 1 Contacts (Skills) still refer to it.', $e->getMessage());
        }

        // Deleting the contact leaves the tag alone, and the skill free to go.
        $this->records->delete('contact', $anna->id);
        self::assertNotNull($this->finder()->find('tag', $press));
        $this->records->delete('skill', $php);
        self::assertNull($this->finder()->find('skill', $php));
    }

    public function testTheLinkedRecordListsTheRecordsLinkingToIt(): void
    {
        $vip = $this->tag('VIP');
        $anna = $this->records->create(new RecordInput('contact', ['full_name' => 'Anna Rossi', 'tags' => [$vip]]));
        $bruno = $this->records->create(new RecordInput('contact', ['full_name' => 'Bruno Bianchi', 'tags' => [$vip]]));
        $carla = $this->records->create(new RecordInput('contact', ['full_name' => 'Carla Neri', 'tags' => [$vip]]));
        $this->records->delete('contact', $carla->id);

        $groups = $this->finder()->referencing('tag', $vip);

        self::assertCount(1, $groups);
        self::assertSame(['contact', 'tags', 'Contacts', 2], [$groups[0]->objectName, $groups[0]->fieldName, $groups[0]->label, $groups[0]->total]);
        self::assertSame([$anna->id, $bruno->id], array_map(static fn ($record): string => $record->id, $groups[0]->records));
        self::assertSame([$bruno->id, $anna->id], array_map(
            static fn (RecordOutput $record): string => $record->id,
            array_reverse($this->finder()->findReferencing('contact', 'tags', $vip)),
        ));
    }

    public function testAFieldThatDoesNotApplyLosesItsLinks(): void
    {
        $manager = self::getContainer()->get(MetadataManagerInterface::class);
        $manager->createField('contact', 'kind', 'select', 'Kind', settings: ['options' => [['value' => 'person', 'label' => 'Person'], ['value' => 'company', 'label' => 'Company']]]);
        $manager->configureFieldCondition('contact', 'tags', 'kind', ['company']);
        $vip = $this->tag('VIP');

        $acme = $this->records->create(new RecordInput('contact', ['full_name' => 'ACME', 'kind' => 'company', 'tags' => [$vip]]));
        self::assertSame([$vip], $acme->get('tags'));

        self::assertSame([], $this->records->update($acme->id, new RecordInput('contact', ['kind' => 'person']))->get('tags'));
    }

    public function testACompositeFormWritesTheLinks(): void
    {
        $vip = $this->tag('VIP');
        $press = $this->tag('Press');
        self::getContainer()->get(MetadataManagerInterface::class)->saveForm('contact_card', "label: Contact card\nobject: contact\nfields: [full_name, tags]\n");
        $forms = self::getContainer()->get(CompositeRecordManagerInterface::class);

        $id = $forms->save('contact_card', null, new CompositeNodeInput(null, ['full_name' => 'Anna Rossi', 'tags' => [$vip, $press]], [], []));
        self::assertEqualsCanonicalizing([$vip, $press], $forms->read('contact_card', $id)?->record->get('tags'));

        $forms->save('contact_card', $id, new CompositeNodeInput($id, ['full_name' => 'Anna Rossi', 'tags' => [$press]], [], []));
        self::assertSame([$press], $this->finder()->find('contact', $id)?->get('tags'));
    }

    private function tag(string $name): string
    {
        return $this->records->create(new RecordInput('tag', ['name' => $name]))->id;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, list<string>>
     */
    private function errors(array $values): array
    {
        try {
            $this->records->create(new RecordInput('contact', $values));
        } catch (RecordValidationFailed $e) {
            return $e->errors;
        }

        return [];
    }

    private function linkDate(string $contactId, string $tagId): string
    {
        $date = $this->connection()->fetchOne('SELECT created_at FROM data.l_contact__tags WHERE record_id = ? AND target_id = ?', [$contactId, $tagId]);
        self::assertIsString($date);

        return $date;
    }

    private function linkCount(string $tagId): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM data.l_contact__tags WHERE target_id = ?', [$tagId]);
        self::assertIsInt($count);

        return $count;
    }

    private function finder(): RecordFinderInterface
    {
        return self::getContainer()->get(RecordFinderInterface::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
