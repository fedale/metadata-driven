<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Metadata;

use App\Module\Metadata\Contract\Dto\TranslationEntryDto;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Contract\MetadataTranslationsInterface;
use App\Module\Metadata\Infrastructure\Translation\LocalizedMetadataRegistry;
use App\Tests\Support\CreatesPetObject;
use App\Tests\Support\CreatesRegistrationForm;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Translations of the metadata: set per key and language, served by the localized registry
 * in the language of the request, checked when they are set and when a {t:name} token is saved.
 */
final class TranslationTest extends KernelTestCase
{
    use CreatesPetObject;
    use CreatesRegistrationForm;

    protected function setUp(): void
    {
        self::createPetObject(self::getContainer());
    }

    public function testServesTheMetadataInTheLanguageOfTheRequest(): void
    {
        self::createRegistrationForm(self::getContainer());
        foreach ([
            'object.pet.singular' => 'Animale',
            'object.pet.plural' => 'Animali',
            'field.pet.name' => 'Nome',
            'option.pet.species.dog' => 'Cane',
            'form.registration' => 'Registrazione',
            'section.registration.profile' => 'Profilo',
            'section.registration.profile.phones' => 'Telefoni',
            'service.registration.terms' => 'Accetto i termini',
        ] as $key => $text) {
            $this->manager()->translate('it', $key, $text);
        }

        $pet = $this->localized('it')->getObject('pet');
        self::assertSame(['Animale', 'Animali', 'Nome'], [$pet->labelSingular, $pet->labelPlural, $pet->field('name')->label]);
        self::assertSame([['value' => 'dog', 'label' => 'Cane'], ['value' => 'cat', 'label' => 'Cat']], $pet->field('species')->settings['options']);
        // Untranslated: the text of the metadata.
        self::assertSame('Weight', $pet->field('weight')->label);

        $form = $this->localized('it')->getForm('registration');
        self::assertSame(['Registrazione', 'Registrazione', 'Accetto i termini'], [$form->label, $form->root->label, $form->root->serviceFields[0]->label]);
        self::assertSame(['Profilo', 'Telefoni'], [$form->root->sections[0]->label, $form->root->sections[0]->sections[0]->label]);
        self::assertSame('Qualifications', $form->root->sections[1]->label);

        // The default language is the metadata as written; so is the registry every other module gets.
        self::assertSame('Pet', $this->localized('en')->getObject('pet')->labelSingular);
        self::assertSame('Pet', self::getContainer()->get(MetadataRegistryInterface::class)->getObject('pet')->labelSingular);
    }

    public function testATextTokenShowsTheTextInTheUsersLanguage(): void
    {
        $this->manager()->translate('en', 'text.identity', 'Identity & chip');
        $this->manager()->translate('it', 'text.identity', 'Identità');
        $this->manager()->translate('en', 'text.kind', 'Kind');
        $this->manager()->saveView('pet', ViewKind::Form, 'main', '<fieldset><legend>{t:identity}</legend>{name} {chip_code}</fieldset><p>{ t:kind }</p>');
        $this->manager()->saveView('pet', ViewKind::Grid, 'main', "columns:\n  - { field: name, label: '{t:identity}' }\n  - species");

        $view = $this->localized('it')->getObject('pet')->view(ViewKind::Form);
        // Not translated in Italian: the English text.
        self::assertSame('<fieldset><legend>Identità</legend>{name} {chip_code}</fieldset><p>Kind</p>', $view?->template);
        self::assertSame('Identity &amp; chip', $this->textOf($this->localized('en')->getObject('pet')->view(ViewKind::Form)->template ?? ''));
        self::assertSame('Identità', $this->localized('it')->getObject('pet')->view(ViewKind::Grid)?->grid?->column('name')?->label);

        // The views keep the tokens as written.
        self::assertStringContainsString('{t:identity}', self::getContainer()->get(MetadataRegistryInterface::class)->getObject('pet')->view(ViewKind::Form)->template ?? '');

        $texts = array_values(array_filter(
            self::getContainer()->get(MetadataTranslationsInterface::class)->entries('it'),
            static fn (TranslationEntryDto $entry): bool => $entry->isText(),
        ));
        self::assertSame(['text.identity', 'text.kind'], array_map(static fn (TranslationEntryDto $entry): string => $entry->key, $texts));
        self::assertSame(['Identity & chip', 'Identità', ['form view pet/main', 'grid view pet/main']], [$texts[0]->source, $texts[0]->translation, $texts[0]->usedBy]);
        self::assertNull($texts[1]->translation);
    }

    public function testRefusesWhatCannotBeTranslated(): void
    {
        self::assertSame(['key' => ['"field.pet.nickname" is no key of the metadata (see Admin > Translations for the keys).']], $this->errorsOf('it', 'field.pet.nickname', 'Soprannome'));
        self::assertSame(['locale' => ['In en, the text of "field.pet.name" is the one of the metadata: change the label itself.']], $this->errorsOf('en', 'field.pet.name', 'Name'));
        self::assertSame(['locale' => ['"fr" is no enabled language (en, it).']], $this->errorsOf('fr', 'field.pet.name', 'Nom'));
        self::assertSame(['key' => ['The text "text.welcome" is not defined: give it a text in en first.']], $this->errorsOf('it', 'text.welcome', 'Benvenuto'));
        self::assertStringStartsWith('Invalid text "text.Bad-Name"', $this->errorsOf('en', 'text.Bad-Name', 'x')['key'][0]);
    }

    public function testATokenNeedsItsTextAndKeepsIt(): void
    {
        self::createRegistrationForm(self::getContainer());
        $missing = '{t:sign_in} is no text: define "text.sign_in" in en first (Admin > Translations > Texts).';
        try {
            $this->manager()->saveView('pet', ViewKind::Form, 'main', '<h2>{t:sign_in}</h2>{name}');
            self::fail('The text is not defined.');
        } catch (MetadataInputInvalid $e) {
            self::assertSame(['template' => ['View "main": '.$missing]], $e->errors);
        }
        try {
            $this->manager()->saveView('pet', ViewKind::Grid, 'main', "columns:\n  - { field: name, label: '{t:sign_in}' }");
            self::fail('The text is not defined.');
        } catch (MetadataInputInvalid $e) {
            self::assertSame(['template' => ['Column #1: '.$missing]], $e->errors);
        }
        try {
            $this->manager()->saveForm('registration', self::REGISTRATION_FORM."\nlayout: '<h2>{t:sign_in}</h2>'");
            self::fail('The text is not defined.');
        } catch (MetadataInputInvalid $e) {
            self::assertSame(['definition' => ['(root): '.$missing]], $e->errors);
        }

        $this->manager()->translate('en', 'text.sign_in', 'Sign in');
        $this->manager()->translate('it', 'text.sign_in', 'Accesso');
        $this->manager()->saveForm('registration', self::REGISTRATION_FORM."\nlayout: '<h2>{t:sign_in}</h2>'");
        self::assertSame('<h2>Accesso</h2>', $this->localized('it')->getForm('registration')->root->layout);

        // Placed by the form: it cannot be removed.
        self::assertSame(['text' => ['{t:sign_in} is still placed by form registration: remove the tokens first.']], $this->errorsOf('en', 'text.sign_in', null));

        // Deleting the form drops its translations; the text is no longer placed, and goes.
        $this->manager()->translate('it', 'form.registration', 'Registrazione');
        $this->manager()->deleteForm('registration');
        $this->manager()->translate('en', 'text.sign_in', '');
        self::assertSame([], array_filter(
            self::getContainer()->get(MetadataTranslationsInterface::class)->entries('it'),
            static fn (TranslationEntryDto $entry): bool => null !== $entry->translation,
        ));
        self::assertSame(['key' => ['The text "text.sign_in" is not defined: give it a text in en first.']], $this->errorsOf('it', 'text.sign_in', 'Accesso'));
    }

    public function testSetsAndListsTranslationsFromTheConsole(): void
    {
        $console = new Application(self::$kernel ?? self::bootKernel());
        $set = new CommandTester($console->find('metadata:translation:set'));

        self::assertSame(Command::SUCCESS, $set->execute(['locale' => 'it', 'key' => 'field.pet.name', 'text' => 'Nome']));
        self::assertStringContainsString('"field.pet.name" set in it.', $set->getDisplay());
        self::assertSame(Command::FAILURE, $set->execute(['locale' => 'it', 'key' => 'field.pet.nickname', 'text' => 'x']));
        self::assertStringContainsString('is no key of the metadata', $set->getDisplay());

        $list = new CommandTester($console->find('metadata:translation:list'));
        $list->execute(['locale' => 'it', '--missing' => true]);
        self::assertStringContainsString('field.pet.species', $list->getDisplay());
        self::assertStringNotContainsString('field.pet.name ', $list->getDisplay());
        self::assertMatchesRegularExpression('/field\.pet\.name\s+Name\s+Nome/', $this->display($console, 'it'));

        self::assertSame(Command::SUCCESS, $set->execute(['locale' => 'it', 'key' => 'field.pet.name']));
        self::assertStringContainsString('"field.pet.name" removed in it.', $set->getDisplay());
    }

    private function display(Application $console, string $locale): string
    {
        $list = new CommandTester($console->find('metadata:translation:list'));
        $list->execute(['locale' => $locale]);

        return $list->getDisplay();
    }

    private function localized(string $locale): MetadataRegistryInterface
    {
        self::getContainer()->get(LocaleSwitcher::class)->setLocale($locale);

        return self::getContainer()->get(LocalizedMetadataRegistry::class);
    }

    private function textOf(string $html): string
    {
        preg_match('#<legend>(.*)</legend>#', $html, $matches);

        return $matches[1] ?? '';
    }

    /**
     * @return array<string, list<string>>
     */
    private function errorsOf(string $locale, string $key, ?string $text): array
    {
        try {
            $this->manager()->translate($locale, $key, $text);
        } catch (MetadataInputInvalid $e) {
            return $e->errors;
        }
        self::fail('The translation is invalid.');
    }

    private function manager(): MetadataManagerInterface
    {
        return self::getContainer()->get(MetadataManagerInterface::class);
    }
}
