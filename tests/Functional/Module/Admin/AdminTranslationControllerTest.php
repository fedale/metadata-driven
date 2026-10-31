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
 * texts of the {t:name} tokens created and edited in the default language.
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
        self::assertSelectorTextSame('.md-switch__item--current', 'Italian');
        self::assertSame('Name', $this->sourceOf($crawler, 'field.pet.name'));

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

    public function testCreatesAndRemovesTextsInTheDefaultLanguage(): void
    {
        self::signInAsSuperAdmin($this->client);
        $crawler = $this->client->request('GET', '/admin/translations/en');
        self::assertSelectorTextContains('main', 'No texts yet.');

        $this->client->submit($crawler->selectButton('Create')->form(['new_text[name]' => 'identity', 'new_text[text]' => 'Identity']));
        self::assertResponseRedirects('/admin/translations/en', 303);
        self::getContainer()->get(MetadataManagerInterface::class)->saveView('pet', ViewKind::Form, 'main', '<h2>{t:identity}</h2>{name}');

        $crawler = $this->client->request('GET', '/admin/translations/en');
        // Only the texts: in the default language the metadata is the text of the other keys.
        self::assertSame(['text.identity'], $crawler->filter('.md-translations tbody code')->each(static fn (Crawler $code): string => $code->text()));
        self::assertSelectorTextContains('.md-translations tbody', 'form view pet/main');

        // Still placed by the view: refused, shown under its input.
        $form = $crawler->selectButton('Save')->form();
        $form[$this->inputOf($crawler, 'text.identity')] = '';
        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.md-translations', '{t:identity} is still placed by form view pet/main: remove the tokens first.');

        // A name taken, or invalid.
        $crawler = $this->client->submit($crawler->selectButton('Create')->form(['new_text[name]' => 'identity', 'new_text[text]' => 'Again']));
        self::assertSelectorTextContains('main', 'A text named "identity" already exists.');
        $this->client->submit($crawler->selectButton('Create')->form(['new_text[name]' => 'Bad name', 'new_text[text]' => 'x']));
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
