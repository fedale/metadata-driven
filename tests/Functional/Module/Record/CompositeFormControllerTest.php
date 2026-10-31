<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Record;

use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Record\Contract\CompositeRecordManagerInterface;
use App\Module\Record\Contract\Dto\CompositeNodeInput;
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
        self::assertStringContainsString('__phones__', (string) $crawler->filter('[data-gridview-collection-prototype-name-value="__phones__"]')->attr('data-gridview-collection-prototype-value'));
        // "inline: true": prefix, number and type on one line.
        self::assertCount(3, $crawler->filter('.gv-collection__row--inline [name^="composite[sections][profile][sections][phones][0][values]"]'));
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
        self::assertSelectorTextContains('.md-flash--success', 'User registration saved.');
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

    public function testLaysEveryNodeOutWithItsLayout(): void
    {
        self::getContainer()->get(MetadataManagerInterface::class)->saveForm('registration', <<<'YAML'
            label: User registration
            object: account
            fields: [username, email, firstname, lastname, password, active]
            service_fields:
              - { name: terms, type: boolean, label: I accept the terms, required: true }
            layout: |
              <div class="md-columns">
                <fieldset><legend>Sign-in</legend>{username} {email} {terms}</fieldset>
                <div>{profile}</div>
              </div>
            sections:
              - name: profile
                label: Profile
                object: profile
                via: account
                layout: '<p class="md-muted">Where you live</p>{city} {zipcode}'
                sections:
                  - { name: phones, label: Phones, object: phone, via: profile, mode: many, min: 1, inline: true }
              - { name: qualifications, label: Qualifications, object: qualification, via: account, mode: many, layout: '<div class="md-columns">{title} {year}</div>' }
            YAML);
        self::signInAsSuperAdmin($this->client);

        $crawler = $this->client->request('GET', '/f/registration/new');
        self::assertResponseIsSuccessful();
        $layout = $crawler->filter('form > .md-view-layout');
        self::assertCount(1, $layout->filter('.md-columns > fieldset [name="composite[values][username]"]'));
        self::assertCount(1, $layout->filter('.md-columns > fieldset [name="composite[service][terms]"]'));
        // A section is placed whole, with its own layout inside it.
        self::assertCount(1, $layout->filter('.md-columns > div > #section-composite_sections_profile .md-view-layout [name="composite[sections][profile][values][city]"]'));
        self::assertSelectorTextContains('#section-composite_sections_profile .md-muted', 'Where you live');
        // What a layout leaves out follows it: the account's names, the profile's gender and
        // phones, the qualifications.
        self::assertCount(0, $layout->filter('[name="composite[values][firstname]"]'));
        self::assertCount(1, $crawler->filter('[name="composite[values][firstname]"]'));
        self::assertCount(1, $crawler->filter('#section-composite_sections_profile #composite_sections_profile_values_gender'));
        self::assertCount(1, $crawler->filter('#section-composite_sections_profile [name="composite[sections][profile][sections][phones][0][values][number]"]'));
        self::assertCount(0, $layout->filter('#section-composite_sections_qualifications'));
        // Each row of a section follows the section's layout, the rows added with "+" too.
        $prototype = (string) $crawler->filter('[data-gridview-collection-prototype-name-value="__qualifications__"]')->attr('data-gridview-collection-prototype-value');
        self::assertMatchesRegularExpression('/class="md-columns">.*name="composite\[sections\]\[qualifications\]\[__qualifications__\]\[values\]\[title\]"/s', $prototype);

        // The names of the inputs do not change: the form saves as before.
        $this->client->request('POST', '/f/registration/new', array_replace_recursive($this->values($crawler), ['composite' => [
            'values' => ['username' => 'mario', 'email' => 'mario@example.com', 'firstname' => 'Mario', 'lastname' => 'Rossi', 'password' => ['first' => 'secret123', 'second' => 'secret123']],
            'service' => ['terms' => '1'],
            'sections' => ['profile' => [
                'values' => ['city' => 'Milano'],
                'sections' => ['phones' => [0 => ['id' => '', 'values' => ['prefix' => '+39', 'number' => '333 123 4567', 'type' => 'mobile']]]],
            ]],
        ]]));
        self::assertResponseRedirects();
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

    public function testRemovingRowsNeedsTheDeletePermissionOfTheirCascades(): void
    {
        // Deleting a phone deletes its calls: an object the form does not show.
        $container = self::getContainer();
        $container->get(CreateObjectHandler::class)(new CreateObjectCommand('call', 'Call', 'Calls'));
        $container->get(CreateFieldHandler::class)(new CreateFieldCommand('call', 'phone', 'relation', 'Phone', settings: ['target' => 'phone', 'onDelete' => 'cascade']));
        $permissions = [];
        foreach (['account', 'profile', 'phone', 'qualification'] as $object) {
            foreach (['read', 'create', 'update', 'delete'] as $action) {
                $permissions[] = \sprintf('record.%s.%s', $object, $action);
            }
        }
        self::signIn($this->client, 'clerk@example.com', [...$permissions, 'record.call.read']);
        $id = $container->get(CompositeRecordManagerInterface::class)->save('registration', null, new CompositeNodeInput(
            values: ['username' => 'mario', 'email' => 'mario@example.com', 'firstname' => 'Mario', 'lastname' => 'Rossi', 'password' => 'secret123'],
            service: ['terms' => true],
            sections: ['profile' => new CompositeNodeInput(values: ['city' => 'Milano'], sections: ['phones' => [new CompositeNodeInput(values: ['number' => '333 123 4567'])]])],
        ));

        // The page opens read-only; saving it is refused.
        $crawler = $this->client->request('GET', '/f/registration/'.$id.'/edit');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectButton('Save'));
        $this->client->request('POST', '/f/registration/'.$id.'/edit', ['composite' => []]);
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
        return $crawler->filter('[name="'.$name.'"]')->closest('.gv-form-row')?->text() ?? '';
    }
}
