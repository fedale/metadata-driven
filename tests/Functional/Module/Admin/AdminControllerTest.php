<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Admin;

use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Tests\Support\SignsIn;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The no-code builder, driven through HTTP as a browser would.
 */
final class AdminControllerTest extends WebTestCase
{
    use SignsIn;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);
    }

    public function testBuildsAnObjectWithFieldsFromTheUi(): void
    {
        $this->createObject('book', 'Book', 'Books');
        self::assertResponseRedirects('/admin/objects/book', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'Object "book" created, with its table.');

        $this->addField('book', 'text', ['name' => 'title', 'label' => 'Title', 'required' => true, 'settings' => ['maxLength' => '200']]);
        $this->addField('book', 'select', ['name' => 'genre', 'label' => 'Genre', 'settings' => ['options' => "novel | Novel\nessay | Essay\npoetry"]]);
        $this->addField('book', 'number', ['name' => 'price', 'label' => 'Price', 'settings' => ['decimals' => '2']]);

        $book = $this->registry()->getObject('book');
        self::assertSame(['title', 'genre', 'price'], array_keys($book->fields));
        self::assertTrue($book->field('title')->isRequired);
        self::assertSame(200, $book->field('title')->settings['maxLength']);
        // assertEquals: JSONB does not keep the key order.
        self::assertEquals([
            ['value' => 'novel', 'label' => 'Novel'],
            ['value' => 'essay', 'label' => 'Essay'],
            ['value' => 'poetry', 'label' => 'poetry'],
        ], $book->field('genre')->settings['options']);

        // The columns exist, and the records grid works at once.
        $columns = self::getContainer()->get(Connection::class)->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = 'data' AND table_name = 'c_book' ORDER BY ordinal_position",
        );
        self::assertSame(['id', 'created_at', 'updated_at', 'created_by', 'deleted_at', 'title', 'genre', 'price'], $columns);
        $this->client->request('GET', '/o/book');
        self::assertResponseIsSuccessful();
    }

    public function testShowsTheErrorsNextToTheInputs(): void
    {
        $this->createObject('Bad Name', 'Book', '');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.gv-form-row:has(#object_name) .gv-form-error', 'lowercase letters');
        self::assertAnySelectorTextContains('.gv-form-error', 'This value should not be blank.');

        $this->createObject('book', 'Book', 'Books');
        $this->createObject('book', 'Book', 'Books');
        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('.gv-form-error', 'An object named "book" already exists.');

        // Settings errors come from the field type itself.
        $this->addField('book', 'select', ['name' => 'genre', 'label' => 'Genre', 'settings' => ['options' => "novel\nnovel"]]);
        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('.gv-form-error', 'value "novel" is duplicated');
        self::assertFalse($this->registry()->getObject('book')->hasField('genre'));
    }

    public function testRelationFieldsOfferTheObjectsAsTargets(): void
    {
        $this->createObject('author', 'Author', 'Authors');
        $this->addField('author', 'text', ['name' => 'full_name', 'label' => 'Full name']);
        $this->createObject('book', 'Book', 'Books');

        $crawler = $this->client->request('GET', '/admin/objects/book/fields/new/relation');
        self::assertSame(['', 'author', 'book'], $crawler->filter('select[name="field[settings][target]"] option')->extract(['value']));

        $this->addField('book', 'relation', ['name' => 'author', 'label' => 'Author', 'settings' => [
            'target' => 'author', 'onDelete' => 'restrict', 'inverseName' => 'books', 'inverseLabel' => 'Books',
        ]]);

        $relation = $this->registry()->getObject('book')->field('author')->relation();
        self::assertNotNull($relation);
        self::assertSame('author', $relation->target);
        self::assertSame('books', $relation->inverseName);

        $this->client->request('GET', '/admin/objects/author');
        self::assertSelectorTextContains('main', 'Pointed at by');
        self::assertSelectorTextContains('main', 'book.author');
    }

    public function testConfiguresLabelsSearchAndRecordLabel(): void
    {
        $this->createObject('author', 'Author', 'Authors');
        $this->addField('author', 'text', ['name' => 'full_name', 'label' => 'Full name']);
        $this->addField('author', 'text', ['name' => 'city', 'label' => 'City']);

        $this->client->request('GET', '/admin/objects/author');
        $this->client->submitForm('Save labels', ['labels[labelSingular]' => 'Writer', 'labels[labelPlural]' => 'Writers']);
        $this->client->followRedirect();
        $this->client->submitForm('Save record label', ['label_field[field]' => 'city']);
        $this->client->followRedirect();
        $this->client->submitForm('Save search', ['search[handler]' => 'phrase', 'search[fields]' => ['full_name']]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'Search saved');

        $author = $this->registry()->getObject('author');
        self::assertSame('Writers', $author->labelPlural);
        self::assertSame('city', $author->labelField);
        self::assertSame('phrase', $author->searchHandler);
        self::assertSame(['full_name'], $author->searchFields);

        $this->client->request('GET', '/admin/objects/author/fields/city');
        $this->client->submitForm('Save', ['field[label]' => 'Town']);
        self::assertSame('Town', $this->registry()->getObject('author')->field('city')->label);
    }

    public function testOffersTheSettingsOfTheRicherTypes(): void
    {
        $this->createObject('member', 'Member', 'Members');
        $crawler = $this->client->request('GET', '/admin/objects/member/fields/new');
        foreach (['Password', 'Link', 'Phone'] as $type) {
            self::assertStringContainsString($type, $crawler->filter('.md-type-list')->text());
        }

        $this->addField('member', 'password', ['name' => 'secret', 'label' => 'Secret', 'settings' => ['minLength' => '10', 'requireDigit' => '1', 'confirm' => '1']]);
        $this->addField('member', 'boolean', ['name' => 'active', 'label' => 'Active', 'settings' => ['default' => '1', 'display' => 'switch']]);
        $this->addField('member', 'text', ['name' => 'zipcode', 'label' => 'Postal code', 'settings' => ['pattern' => '\d{5}']]);

        $member = $this->registry()->getObject('member');
        self::assertSame(10, $member->field('secret')->settings['minLength']);
        self::assertTrue($member->field('secret')->settings['confirm']);
        self::assertSame('switch', $member->field('active')->settings['display']);
        self::assertTrue($member->field('active')->settings['default']);
        self::assertSame('\d{5}', $member->field('zipcode')->settings['pattern']);

        // A password can neither be searched nor name the records: the admin does not offer it.
        $crawler = $this->client->request('GET', '/admin/objects/member');
        self::assertCount(0, $crawler->filter('input[name="search[fields][]"][value="secret"]'));
        self::assertCount(0, $crawler->filter('select[name="label_field[field]"] option[value="secret"]'));
    }

    public function testSetsAConditionAndTheFormFollowsIt(): void
    {
        $this->createObject('course', 'Course', 'Courses');
        $this->addField('course', 'select', ['name' => 'kind', 'label' => 'Kind', 'settings' => ['options' => "online | Online\nclassroom | Classroom"]]);
        $this->addField('course', 'text', ['name' => 'room', 'label' => 'Room']);

        $this->client->request('GET', '/admin/objects/course/fields/room');
        self::assertSelectorTextContains('form[name="condition"]', 'kind: online, classroom');
        $this->client->submitForm('Save condition', ['condition[when]' => 'kind', 'condition[values]' => 'classroom']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'Condition saved.');
        self::assertSelectorTextContains('.md-facts', 'when kind is classroom');
        self::assertSame(['classroom'], $this->registry()->getObject('course')->field('room')->condition?->values);

        // The room's row follows the kind; every row names its field.
        $crawler = $this->client->request('GET', '/o/course/new');
        $room = $crawler->filter('[data-gv-field="room"]');
        self::assertSame('gridview-conditional', $room->attr('data-controller'));
        self::assertSame('kind', $room->attr('data-gridview-conditional-field-value'));
        self::assertSame('["classroom"]', $room->attr('data-gridview-conditional-values-value'));
        self::assertNull($crawler->filter('[data-gv-field="kind"]')->attr('data-controller'));

        // A value the choice never holds is refused, and "Always" removes the condition.
        $this->client->request('GET', '/admin/objects/course/fields/room');
        $this->client->submitForm('Save condition', ['condition[when]' => 'kind', 'condition[values]' => 'webinar']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--error', 'never holds webinar');
        $this->client->submitForm('Save condition', ['condition[when]' => '', 'condition[values]' => '']);
        self::assertNull($this->registry()->getObject('course')->field('room')->condition);
    }

    public function testEditsTheSettingsOfAField(): void
    {
        $this->createObject('member', 'Member', 'Members');
        $this->addField('member', 'text', ['name' => 'code', 'label' => 'Code', 'settings' => ['maxLength' => '10', 'pattern' => '[A-Z]+']]);

        $crawler = $this->client->request('GET', '/admin/objects/member/fields/code');
        // The length shapes the column: shown, not editable.
        self::assertSame('disabled', $crawler->filter('input[name="field_settings[settings][maxLength]"]')->attr('disabled'));
        self::assertSame('[A-Z]+', $crawler->filter('input[name="field_settings[settings][pattern]"]')->attr('value'));

        $this->client->submitForm('Save settings', ['field_settings[settings][pattern]' => '[A-Z0-9]+', 'field_settings[settings][patternMessage]' => 'Capitals and digits.']);
        self::assertResponseRedirects('/admin/objects/member/fields/code', 303);
        $settings = $this->registry()->getObject('member')->field('code')->settings;
        self::assertSame('[A-Z0-9]+', $settings['pattern']);
        self::assertSame(10, $settings['maxLength']);

        $this->client->followRedirect();
        $this->client->submitForm('Save settings', ['field_settings[settings][pattern]' => '[A-Z']);
        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('.gv-form-error', 'not a valid regular expression');
    }

    public function testManagesViewsFromTheUi(): void
    {
        $this->createObject('author', 'Author', 'Authors');
        $this->addField('author', 'text', ['name' => 'full_name', 'label' => 'Full name']);
        $this->addField('author', 'text', ['name' => 'city', 'label' => 'City']);

        $crawler = $this->client->request('GET', '/admin/objects/author');
        $this->client->click($crawler->selectLink('Add a view')->link());
        self::assertSelectorTextContains('main form', '{full_name} {city}');
        $this->client->submitForm('Save', ['view[name]' => 'main', 'view[template]' => '<div class="grid">{city}<script>alert(1)</script></div>']);
        self::assertResponseRedirects('/admin/objects/author', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'View "main" created.');

        $view = $this->registry()->getObject('author')->view(ViewKind::Form);
        self::assertSame('main', $view?->name);
        self::assertSame('<div class="grid">{city}</div>', $view->template);

        // A layout error shows on the form, nothing is saved.
        $this->client->click($this->client->getCrawler()->filter('a[href="/admin/objects/author/views/form/main"]')->link());
        $this->client->submitForm('Save', ['view[template]' => '{nickname}']);
        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('.gv-form-error', 'object "author" has no field "nickname"');
        self::assertSame('<div class="grid">{city}</div>', $this->registry()->getObject('author')->view(ViewKind::Form)?->template);

        $this->client->submitForm('Delete');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'View "main" deleted.');
        self::assertSame([], $this->registry()->getObject('author')->views);
    }

    public function testManagesGridViewsFromTheUi(): void
    {
        $this->createObject('author', 'Author', 'Authors');
        $this->addField('author', 'text', ['name' => 'full_name', 'label' => 'Full name']);
        $this->addField('author', 'text', ['name' => 'city', 'label' => 'City']);

        $this->client->request('GET', '/admin/objects/author/views/new');
        // Every error of the definition at once, on the input.
        $this->client->submitForm('Save', ['view[name]' => 'list', 'view[kind]' => 'grid', 'view[template]' => "columns: [nickname]\npage_size: 0"]);
        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('.gv-form-error', 'Column #1: object "author" has no field "nickname".');
        self::assertAnySelectorTextContains('.gv-form-error', '"page_size" must be an integer from 1 to 500.');

        $definition = "columns:\n  - { field: city, label: Town }\n  - full_name";
        $this->client->submitForm('Save', ['view[name]' => 'list', 'view[kind]' => 'grid', 'view[template]' => $definition]);
        self::assertResponseRedirects('/admin/objects/author', 303);
        self::assertSame(['city', 'full_name'], array_map(static fn ($column): string => $column->key, $this->registry()->getObject('author')->view(ViewKind::Grid)->grid->columns ?? []));

        $crawler = $this->client->request('GET', '/admin/objects/author/views/grid/list');
        self::assertSelectorTextContains('label', 'Grid definition (YAML)');
        self::assertSame($definition, $crawler->filter('textarea[name="view[template]"]')->text(null, false));
        self::assertSame('/o/author/grid/list', $crawler->filter('.md-facts a')->attr('href'));
    }

    public function testShowsAndRepairsTableDrift(): void
    {
        $this->createObject('author', 'Author', 'Authors');
        $this->addField('author', 'text', ['name' => 'full_name', 'label' => 'Full name']);
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('ALTER TABLE data.c_author DROP COLUMN full_name');
        $connection->executeStatement('ALTER TABLE data.c_author ADD legacy TEXT');

        $this->client->request('GET', '/admin/objects/author');
        self::assertSelectorTextContains('.md-sql', 'ADD full_name');
        self::assertSelectorTextContains('main', 'Column "legacy" is not defined in the metadata: not dropped.');

        $this->client->submitForm('Apply');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'change(s) applied');
        self::assertSelectorNotExists('.md-sql');
    }

    private function createObject(string $name, string $singular, string $plural): void
    {
        $this->client->request('GET', '/admin/objects/new');
        $this->client->submitForm('Create', ['object[name]' => $name, 'object[labelSingular]' => $singular, 'object[labelPlural]' => $plural]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function addField(string $object, string $type, array $values): void
    {
        $crawler = $this->client->request('GET', \sprintf('/admin/objects/%s/fields/new/%s', $object, $type));
        $form = $crawler->selectButton('Create')->form();
        $data = $form->getPhpValues();
        self::assertIsArray($data['field']);
        foreach ($values as $key => $value) {
            if ('settings' === $key && \is_array($value)) {
                $data['field']['settings'] = $value + (\is_array($data['field']['settings'] ?? null) ? $data['field']['settings'] : []);
            } elseif (true === $value) {
                $data['field'][$key] = '1';
            } else {
                $data['field'][$key] = $value;
            }
        }
        $this->client->request('POST', $form->getUri(), $data);
    }

    private function registry(): MetadataRegistryInterface
    {
        return self::getContainer()->get(MetadataRegistryInterface::class);
    }
}
