<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Tests\Support\CreatesPetObject;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The record pages in the user's language: the one of the profile, or else the browser's.
 * The labels come from the translations of the metadata, the {t:name} tokens from its texts,
 * and the grid's own texts from the gridview-bundle.
 */
final class LocalizedRecordPagesTest extends WebTestCase
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

        $metadata = self::getContainer()->get(MetadataManagerInterface::class);
        foreach (['object.pet.plural' => 'Animali', 'object.pet.singular' => 'Animale', 'field.pet.name' => 'Nome', 'field.pet.species' => 'Specie', 'option.pet.species.dog' => 'Cane'] as $key => $text) {
            $metadata->translate('it', $key, $text);
        }
        $metadata->translate('en', 'text.identity', 'Identity');
        $metadata->translate('it', 'text.identity', 'Identità');
        $metadata->saveView('pet', ViewKind::Form, 'main', '<fieldset><legend>{t:identity}</legend>{name} {species}</fieldset>');
    }

    public function testThePagesFollowTheLanguageOfTheProfile(): void
    {
        self::getContainer()->get(UserManagerInterface::class)->changeLocale('admin@example.com', 'it');

        $crawler = $this->client->request('GET', '/o/pet');
        self::assertResponseIsSuccessful();
        self::assertSame('it', $crawler->filter('html')->attr('lang'));
        self::assertPageTitleSame('Animali');
        $headings = $crawler->filter('table[data-gv="record_pet"] thead.gv-thead-columns th[data-col-key]')->each(static fn ($th): string => trim($th->text()));
        self::assertSame(['Nome', 'Specie', 'Weight'], \array_slice($headings, 0, 3));
        // The grid's own texts, from the bundle's Italian catalog.
        self::assertSelectorExists('input[placeholder="Cerca…"]');

        $crawler = $this->client->request('GET', '/o/pet/new');
        self::assertSelectorTextSame('main form legend', 'Identità');
        self::assertSelectorTextContains('main form', 'Nome');
        self::assertSame('Cane', trim($crawler->filter('select[name$="[species]"] option[value="dog"]')->text()));
        self::assertSelectorTextSame('.md-locale__option--current', 'IT');
        // The texts of the application itself, from translations/messages.it.yaml.
        self::assertSelectorTextSame('h1', 'Nuovo: animale');
        self::assertSelectorTextSame('.md-topbar__nav a', 'Dati');
        // In markup order: "Save" comes before "Save and new" (the stylesheet shows it last).
        // "Nomi tecnici" first: the user is an administrator.
        self::assertSame(['Nomi tecnici', 'Annulla', 'Salva', 'Salva e crea nuovo'], $crawler->filter('.md-sheet__header .md-sheet__actions > *')->each(static fn ($action): string => trim($action->text())));
        self::assertSelectorTextContains('.gv-required-note', 'Campo obbligatorio');
    }

    public function testWithoutAPreferenceTheBrowsersLanguageIsUsed(): void
    {
        $this->client->request('GET', '/o/pet', server: ['HTTP_ACCEPT_LANGUAGE' => 'it-IT,it;q=0.9,en;q=0.5']);
        self::assertPageTitleSame('Animali');

        $this->client->request('GET', '/o/pet', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9']);
        self::assertPageTitleSame('Pets');
    }

    public function testTheTopBarSwitchesTheLanguage(): void
    {
        $crawler = $this->client->request('GET', '/o/pet/new');
        self::assertSelectorTextSame('main form legend', 'Identity');

        $this->client->submit($crawler->filter('.md-locale')->selectButton('IT')->form(), [], ['HTTP_REFERER' => 'http://localhost/o/pet/new']);
        self::assertResponseRedirects('/o/pet/new', 303);
        $this->client->followRedirect();
        self::assertSelectorTextSame('main form legend', 'Identità');

        // Back to English, from any page of the site only.
        $crawler = $this->client->request('GET', '/o/pet');
        $this->client->submit($crawler->filter('.md-locale')->selectButton('EN')->form(), [], ['HTTP_REFERER' => 'https://evil.example/o/pet']);
        self::assertResponseRedirects('/', 303);
        $this->client->request('GET', '/o/pet');
        self::assertPageTitleSame('Pets');
    }

    public function testRefusesALanguageThatIsNotEnabled(): void
    {
        $crawler = $this->client->request('GET', '/o/pet');
        $token = (string) $crawler->filter('.md-locale input[name="_token"]')->attr('value');
        $this->client->request('POST', '/account/locale', ['locale' => 'fr', '_token' => $token]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--error', '"fr" is no language of the application (en, it).');
    }
}
