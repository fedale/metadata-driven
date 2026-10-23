<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Admin;

use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Tests\Support\CreatesRegistrationForm;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Composite forms written and checked from the admin UI.
 */
final class AdminFormControllerTest extends WebTestCase
{
    use CreatesRegistrationForm;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signInAsSuperAdmin($this->client);
        self::createRegistrationForm(self::getContainer());
    }

    public function testWritesAFormAndShowsEveryErrorOfItsDefinition(): void
    {
        $crawler = $this->client->request('GET', '/admin/forms');
        self::assertSelectorTextContains('table', 'User registration');
        $this->client->click($crawler->selectLink('New form')->link());
        self::assertSelectorTextContains('.facts', 'via profile → profile');

        $this->client->submitForm('Save', [
            'form_definition[name]' => 'phonebook',
            'form_definition[definition]' => "label: Phone book\nobject: profile\nsections:\n  - { name: phones, label: Phones, object: phone, via: account, mode: many }\n  - { name: notes, object: note }",
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('ul li', 'phones: "account" is not a relation field of "phone" pointing at "profile" (candidates: profile).');
        self::assertAnySelectorTextContains('ul li', 'notes: "label" must be a text');
        self::assertFalse($this->registry()->hasForm('phonebook'));

        $this->client->submitForm('Save', [
            'form_definition[name]' => 'phonebook',
            'form_definition[definition]' => "label: Phone book\nobject: profile\nfields: [city]\nsections:\n  - { name: phones, label: Phones, object: phone, via: profile, mode: many }",
        ]);
        self::assertResponseRedirects('/admin/forms/phonebook', 303);
        self::assertSame(['profile', 'phone'], $this->registry()->getForm('phonebook')->root->objects());

        $this->client->followRedirect();
        $this->client->submitForm('Delete');
        self::assertResponseRedirects('/admin/forms', 303);
        self::assertFalse($this->registry()->hasForm('phonebook'));
    }

    public function testTheHomePageOffersTheForms(): void
    {
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('main', 'User registration');
    }

    private function registry(): MetadataRegistryInterface
    {
        return self::getContainer()->get(MetadataRegistryInterface::class);
    }
}
