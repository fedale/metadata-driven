<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\CreatesPlaces;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The record forms of an object holding a field of every type and every display, compared with
 * a stored copy of their HTML.
 *
 * It guards the move of the controls to fedale/gridview-bundle (see
 * docs/internal/controls-alignment-plan.md): each step must leave the forms as they were, unless
 * it means to change them. To accept an intended change, run the test with GOLDEN_UPDATE=1 and
 * review the diff of the stored copies.
 */
final class GoldenRecordFormsTest extends WebTestCase
{
    use CreatesPlaces;
    use SignsIn;

    private const string DIRECTORY = __DIR__.'/../../../Fixtures/golden-forms';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);
    }

    public function testTheNewRecordFormIsUnchanged(): void
    {
        $this->createSpecimen();

        $this->assertGolden('specimen.new.html', $this->form('/o/specimen/new'));
    }

    public function testTheEditFormIsUnchanged(): void
    {
        $places = $this->createSpecimen();
        $records = self::getContainer()->get(RecordManagerInterface::class);
        $tag = $records->create(new RecordInput('tag', ['name' => 'Rare']))->id;
        $id = $records->create(new RecordInput('specimen', [
            'title' => 'Blue morpho',
            'notes' => "Seen twice.\nBoth in the morning.",
            'count' => 3,
            'price' => '12.50',
            'active' => true,
            'featured' => false,
            'born_on' => '2026-05-01',
            'seen_at' => '2026-05-02 09:30:00',
            'email' => 'curator@example.org',
            'phone' => '+39 02 1234 5678',
            'site' => 'https://example.org/morpho',
            'kind' => 'course',
            'hours' => 12,
            'size' => 'large',
            'region' => $places['lombardia'],
            'province' => $places['mi'],
            'tags' => [$tag],
        ]))->id;

        $this->assertGolden('specimen.edit.html', $this->form(\sprintf('/o/specimen/%s/edit', $id)));
    }

    /**
     * @return array<string, string> the ids of the places (see CreatesPlaces)
     */
    private function createSpecimen(): array
    {
        $places = self::createPlaces(self::getContainer());
        $metadata = self::getContainer()->get(MetadataManagerInterface::class);

        $metadata->createObject('tag', 'Tag', 'Tags');
        $metadata->createField('tag', 'name', 'text', 'Name', true);
        $metadata->createObject('label', 'Label', 'Labels');
        $metadata->createField('label', 'name', 'text', 'Name', true);

        $metadata->createObject('specimen', 'Specimen', 'Specimens');
        $metadata->createField('specimen', 'title', 'text', 'Title', true, settings: ['maxLength' => 40]);
        $metadata->createField('specimen', 'notes', 'text', 'Notes', settings: ['multiline' => true]);
        $metadata->createField('specimen', 'code', 'text', 'Code', settings: ['pattern' => '[A-Z]{3}', 'patternMessage' => 'Three capital letters.']);
        $metadata->createField('specimen', 'count', 'number', 'Count', settings: ['min' => 1, 'max' => 10]);
        $metadata->createField('specimen', 'price', 'number', 'Price', settings: ['decimals' => 2]);
        $metadata->createField('specimen', 'active', 'boolean', 'Active');
        $metadata->createField('specimen', 'featured', 'boolean', 'Featured', settings: ['default' => true, 'display' => 'switch']);
        $metadata->createField('specimen', 'born_on', 'date', 'Born on');
        $metadata->createField('specimen', 'seen_at', 'date', 'Seen at', settings: ['includeTime' => true]);
        $metadata->createField('specimen', 'email', 'email', 'Email');
        $metadata->createField('specimen', 'phone', 'tel', 'Phone');
        $metadata->createField('specimen', 'site', 'url', 'Site');
        $metadata->createField('specimen', 'kind', 'select', 'Kind', true, settings: ['options' => [
            ['value' => 'course', 'label' => 'Course'],
            ['value' => 'language', 'label' => 'Language'],
        ]]);
        $metadata->createField('specimen', 'hours', 'number', 'Hours');
        $metadata->configureFieldCondition('specimen', 'hours', 'kind', ['course']);
        $metadata->createField('specimen', 'size', 'select', 'Size', settings: ['display' => 'radio', 'options' => [
            ['value' => 'small', 'label' => 'Small'],
            ['value' => 'large', 'label' => 'Large'],
        ]]);
        $metadata->createField('specimen', 'state', 'status', 'State', settings: [
            'states' => [['value' => 'draft', 'label' => 'Draft'], ['value' => 'published', 'label' => 'Published']],
        ]);
        $metadata->createField('specimen', 'secret', 'password', 'Secret', settings: [
            'minLength' => 10, 'confirm' => true, 'strengthMeter' => true, 'revealToggle' => true,
        ]);
        $metadata->createField('specimen', 'pin', 'password', 'PIN');
        $metadata->createField('specimen', 'region', 'relation', 'Region', settings: ['target' => 'region']);
        $metadata->createField('specimen', 'province', 'relation', 'Province', settings: ['target' => 'province', 'dependsOn' => 'region', 'dependsVia' => 'region']);
        $metadata->createField('specimen', 'tags', 'many_to_many', 'Tags', settings: ['target' => 'tag']);
        $metadata->createObject('specimen_label', 'Specimen label', 'Specimen labels');
        $metadata->createField('specimen_label', 'specimen', 'relation', 'Specimen', true, settings: ['target' => 'specimen', 'onDelete' => 'cascade']);
        $metadata->createField('specimen_label', 'label', 'relation', 'Label', true, settings: ['target' => 'label', 'onDelete' => 'cascade']);
        $metadata->createField('specimen_label', 'note', 'text', 'Note');
        $metadata->addUniqueTogether('specimen_label', ['specimen', 'label']);
        $metadata->createField('specimen', 'labels', 'many_to_many', 'Labels', settings: ['target' => 'label', 'through' => 'specimen_label', 'throughSource' => 'specimen', 'throughTarget' => 'label', 'max' => 3]);
        $metadata->createField('specimen', 'manual', 'file', 'Manual', settings: ['accept' => 'application/pdf', 'maxSize' => 2]);
        $metadata->createField('specimen', 'photos', 'file', 'Photos', settings: ['image' => true, 'multiple' => true, 'max' => 4]);

        return $places;
    }

    /**
     * The record form of a page, one tag per line, with what changes from one run to the next
     * (record ids, the CSRF token) replaced by placeholders.
     */
    private function form(string $url): string
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $html = $crawler->filter('form[name="record"]')->outerHtml();
        $html = (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '{uuid}', $html);
        $html = (string) preg_replace('/(name="record\[_token\]"[^>]*value=")[^"]*"/', '$1{token}"', $html);
        $html = (string) preg_replace('/>\s*</', ">\n<", $html);

        return trim($html)."\n";
    }

    private function assertGolden(string $file, string $actual): void
    {
        $path = self::DIRECTORY.'/'.$file;
        if ('1' === getenv('GOLDEN_UPDATE') || !is_file($path)) {
            if (!is_dir(self::DIRECTORY)) {
                mkdir(self::DIRECTORY, 0o777, true);
            }
            file_put_contents($path, $actual);
            if ('1' !== getenv('GOLDEN_UPDATE')) {
                self::fail(\sprintf('The stored copy %s did not exist: it is written now, review it and run the test again.', $file));
            }
        }

        self::assertSame(file_get_contents($path), $actual, \sprintf('The form differs from %s. If the change is intended, run with GOLDEN_UPDATE=1 and review the diff.', $file));
    }
}
