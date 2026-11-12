<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Admin;

use App\Module\User\Contract\UserManagerInterface;
use App\Tests\Support\CreatesPetObject;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The admin pages in the language of the administrator, from translations/messages.<locale>.yaml.
 * The labels of the metadata stay as written: their translations are for the record pages.
 */
final class LocalizedAdminPagesTest extends WebTestCase
{
    use CreatesPetObject;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);
        self::createPetObject(self::getContainer());
        self::getContainer()->get(UserManagerInterface::class)->changeLocale('admin@example.com', 'it');
    }

    public function testEveryAdminPageIsInTheLanguageOfTheUser(): void
    {
        foreach ([
            '/admin' => 'Oggetti',
            '/admin/objects/new' => 'Nuovo oggetto',
            '/admin/objects/pet' => 'Pets',
            '/admin/objects/pet/fields/new' => 'Nuovo campo: scegli il tipo',
            '/admin/objects/pet/fields/new/text' => 'Nuovo campo (testo) su Pets',
            '/admin/objects/pet/fields/species' => 'Species',
            '/admin/objects/pet/views/new' => 'Nuova vista',
            '/admin/forms' => 'Moduli',
            '/admin/forms/new' => 'Nuovo modulo',
            '/admin/translations/it' => 'Traduzioni',
            '/admin/texts' => 'Traduzioni',
            '/admin/templates' => 'Template',
        ] as $url => $heading) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertStringStartsWith($heading, trim($crawler->filter('h1')->text()), $url);
            // A key with no text would show as itself.
            self::assertDoesNotMatchRegularExpression('/\badmin\.[a-z_]+\.[a-z_]/', $crawler->filter('main')->html(), $url);
        }
    }

    public function testTheFormsAndTheMessagesAreTranslatedToo(): void
    {
        $this->client->request('GET', '/admin/objects/pet');
        self::assertSelectorTextContains('label[for="labels_labelSingular"]', 'Etichetta al singolare');
        self::assertSelectorTextContains('.md-table', 'obbligatorio');
        self::assertSelectorTextContains('.md-flash--success', 'La tabella corrisponde ai metadati.');

        $this->client->submitForm('Salva le etichette', ['labels[labelSingular]' => 'Pet', 'labels[labelPlural]' => 'Pets']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'Etichette salvate.');

        // The texts of the field types, from translations/field_types.it.yaml.
        $crawler = $this->client->request('GET', '/admin/objects/pet/fields/new');
        self::assertContains('Sì / No', $crawler->filter('.md-type-list__card strong')->each(static fn ($name): string => trim($name->text())));
        $this->client->request('GET', '/admin/objects/pet/fields/new/select');
        self::assertSelectorTextContains('label[for="field_settings_display"]', 'Aspetto');
        self::assertSelectorTextContains('select[name="field[settings][display]"]', 'Menu a tendina');
        $this->client->request('GET', '/admin/objects/pet');
        self::assertSelectorTextContains('.md-table', 'Scelta');

        $crawler = $this->client->request('GET', '/admin/texts');
        // The names of the languages, in the language of the page.
        self::assertSelectorTextContains('label[for="new_text_it"]', 'Italiano (facoltativo)');
        self::assertSelectorTextContains('label[for="new_text_en"]', 'Inglese');
    }
}
