<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Admin;

use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataTranslationsInterface;
use App\Tests\Support\CreatesPetObject;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Admin > Translations: the keys of the metadata translated one language at a time, and the
 * texts of the {t:name} tokens written in every language at once.
 */
final class AdminTranslationControllerTest extends WebTestCase
{
    use CreatesPetObject;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::createPetObject(self::getContainer());
    }

    public function testTranslatesTheMetadataIntoALanguage(): void
    {
        self::signInAsSuperAdmin($this->client);
        $this->client->request('GET', '/admin/translations');
        self::assertResponseRedirects('/admin/translations/it');

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextSame('.md-switch__item--current', 'Metadata');
        self::assertSelectorTextContains('h1', 'Metadata into Italian');
        self::assertSame(['Metadata', 'Texts'], $crawler->filter('.md-switch a')->each(static fn (Crawler $link): string => $link->text()));
        self::assertSame('Name Edit', $this->sourceOf($crawler, 'field.pet.name'));
        // The original text is changed where it is defined.
        self::assertSame('/admin/objects/pet/fields/species', $this->rowOf($crawler, 'option.pet.species.dog')->filter('a')->attr('href'));
        // Nothing to translate into the default language.
        $this->client->request('GET', '/admin/translations/en');
        self::assertResponseStatusCodeSame(404);

        $form = $crawler->selectButton('Save')->form();
        $form[$this->inputOf($crawler, 'field.pet.name')] = 'Nome';
        $form[$this->inputOf($crawler, 'option.pet.species.dog')] = 'Cane';
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/translations/it', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', '2 texts saved.');
        self::assertSame(['field.pet.name' => 'Nome', 'option.pet.species.dog' => 'Cane'], $this->translated('it'));

        // Emptied: the English text again.
        $form = $crawler->selectButton('Save')->form();
        $form[$this->inputOf($crawler, 'field.pet.name')] = '';
        $this->client->submit($form);
        self::assertSame(['option.pet.species.dog' => 'Cane'], $this->translated('it'));
    }

    public function testWritesTheTextsInEveryLanguage(): void
    {
        self::signInAsSuperAdmin($this->client);
        $crawler = $this->client->request('GET', '/admin/texts');
        self::assertSelectorTextContains('main', 'No texts yet.');

        // The default language is required; the others may follow.
        $this->client->submit($crawler->selectButton('Create')->form(['new_text[name]' => 'identity', 'new_text[en]' => 'Identity', 'new_text[it]' => 'Identità']));
        self::assertResponseRedirects('/admin/texts', 303);
        $this->client->submit($this->client->request('GET', '/admin/texts')->selectButton('Create')->form(['new_text[name]' => 'kind', 'new_text[en]' => 'Kind']));
        self::getContainer()->get(MetadataManagerInterface::class)->saveView('pet', ViewKind::Form, 'main', '<h2>{t:identity}</h2>{name}');

        $crawler = $this->client->request('GET', '/admin/texts');
        self::assertSame(['{t:identity}', '{t:kind}'], $crawler->filter('.md-texts tbody code')->each(static fn (Crawler $code): string => $code->text()));
        self::assertSelectorTextContains('.md-texts tbody tr:first-child', 'In form view pet/main');
        self::assertSelectorTextContains('.md-texts tbody tr:last-child', 'Not placed yet');
        // Not on the translations page: a text is written in one place.
        $this->client->request('GET', '/admin/translations/it');
        self::assertSelectorTextNotContains('main', 'text.identity');

        // Both languages of a text at once.
        $form = $crawler->selectButton('Save')->form(['texts[x1_it]' => 'Specie', 'texts[x1_en]' => 'Kind of pet']);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/texts', 303);
        $texts = self::getContainer()->get(MetadataTranslationsInterface::class)->texts();
        self::assertSame(['en' => 'Kind of pet', 'it' => 'Specie'], $texts[1]->texts);

        // Still placed by the view: refused, shown under its input.
        $crawler = $this->client->request('GET', '/admin/texts');
        $crawler = $this->client->submit($crawler->selectButton('Save')->form(['texts[x0_en]' => '']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.md-texts', '{t:identity} is still placed by form view pet/main: remove the tokens first.');

        // Not placed: emptied in the default language, it goes in every language.
        $this->client->submit($crawler->selectButton('Save')->form(['texts[x0_en]' => 'Identity', 'texts[x1_en]' => '']));
        self::assertResponseRedirects('/admin/texts', 303);
        self::assertSame(['identity'], array_map(static fn ($text): string => $text->name, self::getContainer()->get(MetadataTranslationsInterface::class)->texts()));

        // A name taken, or invalid.
        $crawler = $this->client->request('GET', '/admin/texts');
        $crawler = $this->client->submit($crawler->selectButton('Create')->form(['new_text[name]' => 'identity', 'new_text[en]' => 'Again']));
        self::assertSelectorTextContains('main', 'A text named "identity" already exists.');
        $this->client->submit($crawler->selectButton('Create')->form(['new_text[name]' => 'Bad name', 'new_text[en]' => 'x']));
        self::assertSelectorTextContains('main', 'Invalid text "text.Bad name"');
    }

    public function testIsForAdministratorsOnly(): void
    {
        self::signIn($this->client, 'user@example.com');
        $this->client->request('GET', '/admin/translations/it');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array<string, string> the translated keys, by key
     */
    private function translated(string $locale): array
    {
        $texts = [];
        foreach (self::getContainer()->get(MetadataTranslationsInterface::class)->entries($locale) as $entry) {
            if (null !== $entry->translation) {
                $texts[$entry->key] = $entry->translation;
            }
        }

        return $texts;
    }

    private function inputOf(Crawler $crawler, string $key): string
    {
        return (string) $this->rowOf($crawler, $key)->filter('input')->attr('name');
    }

    private function sourceOf(Crawler $crawler, string $key): string
    {
        return trim($this->rowOf($crawler, $key)->filter('td')->eq(1)->text());
    }

    private function rowOf(Crawler $crawler, string $key): Crawler
    {
        return $crawler->filter('.md-translations tbody tr')->reduce(static fn (Crawler $row): bool => $row->filter('code')->text() === $key);
    }
}
