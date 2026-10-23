<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\Record\Contract\RecordFinderInterface;
use App\Tests\Support\CreatesRegistrationForm;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The registration composite form driven through HTTP, as a browser would: the rows added
 * with "+" are posted with keys of their own, the rows removed are not posted.
 */
final class CompositeFormControllerTest extends WebTestCase
{
    use CreatesRegistrationForm;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::createRegistrationForm(self::getContainer());
    }

    public function testRegistersAnAccountWithItsProfileAndPhones(): void
    {
        self::signInAsSuperAdmin($this->client);
        $crawler = $this->client->request('GET', '/f/registration/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'User registration');
        self::assertSelectorTextContains('#section-composite_sections_profile legend', 'Profile');
        // Phones need one row: it is there, with the defaults of its fields.
        self::assertSame('+39', $crawler->filter('select[name="composite[sections][profile][sections][phones][0][values][prefix]"] option[selected]')->attr('value'));
        self::assertStringContainsString('__phones__', (string) $crawler->filter('[data-collection-prototype-name-value="__phones__"]')->attr('data-collection-prototype-value'));
        // "inline: true": prefix, number and type on one line.
        self::assertCount(3, $crawler->filter('.composite-inline [name^="composite[sections][profile][sections][phones][0][values]"]'));
        // An inline row shows its labels as placeholders, the required ones marked.
        self::assertSame('Number *', $crawler->filter('input[name="composite[sections][profile][sections][phones][0][values][number]"]')->attr('placeholder'));
        // The city is required by the form only: marked all the same.
        self::assertStringContainsString('required', (string) $crawler->filter('label[for="composite_sections_profile_values_city"]')->attr('class'));
        self::assertStringNotContainsString('required', (string) $crawler->filter('label[for="composite_sections_profile_values_zipcode"]')->attr('class'));
        self::assertCount(0, $crawler->filter('[name^="composite[sections][qualifications]"]'));
        // A new account needs its password: required, without the "keep it" help.
        self::assertSame('required', $crawler->filter('input[name="composite[values][password][first]"]')->attr('required'));
        self::assertSelectorTextNotContains('form', 'Leave empty to keep the current password.');

        $this->client->request('POST', '/f/registration/new', array_replace_recursive($this->values($crawler), ['composite' => [
            'values' => ['username' => 'mario', 'email' => 'mario@example.com', 'firstname' => 'Mario', 'lastname' => 'Rossi', 'password' => ['first' => 'secret123', 'second' => 'secret123'], 'active' => '1'],
            'service' => ['terms' => '1'],
            'sections' => [
                'profile' => [
                    'values' => ['gender' => 'f', 'city' => 'Milano', 'zipcode' => '20121'],
                    // The first phone row, plus one added with "+" under a key of its own.
                    'sections' => ['phones' => [
                        0 => ['id' => '', 'values' => ['prefix' => '+39', 'number' => '333 123 4567', 'type' => 'mobile']],
                        3 => ['id' => '', 'values' => ['prefix' => '+41', 'number' => '044 123 45 67', 'type' => 'office']],
                    ]],
                ],
                'qualifications' => [1 => ['id' => '', 'values' => ['title' => 'Computer engineering', 'year' => '2019']]],
            ],
        ]]));

        $account = self::getContainer()->get(RecordFinderInterface::class)->latest('account', 1)[0] ?? null;
        self::assertNotNull($account);
        self::assertResponseRedirects('/f/registration/'.$account->id.'/edit', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'User registration saved.');
        self::assertSame('Milano', $crawler->filter('input[name="composite[sections][profile][values][city]"]')->attr('value'));
        self::assertSame(['333 123 4567', '044 123 45 67'], $this->numbers($crawler));
        self::assertSame('Computer engineering', $crawler->filter('input[name="composite[sections][qualifications][0][values][title]"]')->attr('value'));

        // Remove the office phone ("Remove" drops its inputs); the password left empty is kept.
        $form = $crawler->selectButton('Save')->form();
        foreach (array_keys($form->all()) as $name) {
            if (str_starts_with($name, 'composite[sections][profile][sections][phones][1]')) {
                $form->remove($name);
            }
        }
        $form->setValues(['composite[service][terms]' => '1']);
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertSame(['333 123 4567'], $this->numbers($this->client->followRedirect()));
    }

    public function testShowsTheErrorsOnTheNestedInputs(): void
    {
        self::signInAsSuperAdmin($this->client);
        $form = $this->client->request('GET', '/f/registration/new')->selectButton('Save')->form([
            'composite[values][username]' => 'mario',
            'composite[sections][profile][values][zipcode]' => '123',
            'composite[sections][profile][sections][phones][0][values][number]' => 'call me',
        ]);

        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This value is required.', $this->errorsOf($crawler, 'composite[values][email]'));
        self::assertStringContainsString('This box must be checked.', $crawler->filter('form')->text());
        self::assertStringContainsString('This value is not valid.', $this->errorsOf($crawler, 'composite[sections][profile][values][zipcode]'));
        self::assertStringContainsString('not a valid phone number', $this->errorsOf($crawler, 'composite[sections][profile][sections][phones][0][values][number]'));
        self::assertSame([], self::getContainer()->get(RecordFinderInterface::class)->latest('account'));
    }

    public function testNeedsThePermissionsOfEveryObject(): void
    {
        // May create accounts and profiles, but not phones nor qualifications.
        self::signIn($this->client, 'clerk@example.com', ['record.account.read', 'record.account.create', 'record.profile.read', 'record.profile.create']);

        $this->client->request('GET', '/f/registration/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnknownFormIsNotFound(): void
    {
        self::signInAsSuperAdmin($this->client);
        $this->client->request('GET', '/f/ghost/new');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array<mixed> the values the page's form would post
     */
    private function values(Crawler $crawler): array
    {
        return $crawler->selectButton('Save')->form()->getPhpValues();
    }

    /**
     * @return list<string|null> the phone numbers on the page, in order
     */
    private function numbers(Crawler $crawler): array
    {
        return $crawler->filter('input[name$="[values][number]"]')->each(static fn (Crawler $input): ?string => $input->attr('value'));
    }

    private function errorsOf(Crawler $crawler, string $name): string
    {
        // The errors of an input are listed in its row.
        return $crawler->filter('[name="'.$name.'"]')->closest('div')?->text() ?? '';
    }
}
