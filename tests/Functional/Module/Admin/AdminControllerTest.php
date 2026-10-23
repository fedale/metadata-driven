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

    public function testManagesViewsFromTheUi(): void
    {
        $this->createObject('author', 'Author', 'Authors');
        $this->addField('author', 'text', ['name' => 'full_name', 'label' => 'Full name']);
        $this->addField('author', 'text', ['name' => 'city', 'label' => 'City']);

        $crawler = $this->client->request('GET', '/admin/objects/author');
        $this->client->click($crawler->selectLink('Add a view')->link());
        self::assertSelectorTextContains('form', '{full_name} {city}');
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
