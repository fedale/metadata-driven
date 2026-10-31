<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Record;

use App\Module\Metadata\Contract\Dto\FormNodeMode;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\CompositeRecordManagerInterface;
use App\Module\Record\Contract\Dto\CompositeNodeInput;
use App\Module\Record\Contract\Dto\CompositeRecord;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Exception\CompositeValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Tests\Support\CreatesRegistrationForm;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A composite form (account, profile, phones, qualifications) against the real database.
 */
final class CompositeRecordManagerTest extends KernelTestCase
{
    use CreatesRegistrationForm;

    private CompositeRecordManagerInterface $forms;
    private RecordFinderInterface $finder;

    protected function setUp(): void
    {
        self::createRegistrationForm(self::getContainer());
        $this->forms = self::getContainer()->get(CompositeRecordManagerInterface::class);
        $this->finder = self::getContainer()->get(RecordFinderInterface::class);
    }

    public function testTheDefinitionIsCompiledIntoNestedNodes(): void
    {
        $form = self::getContainer()->get(MetadataRegistryInterface::class)->getForm('registration');

        self::assertSame('User registration', $form->label);
        self::assertSame('account', $form->root->object);
        self::assertSame(['profile', 'qualifications'], array_map(static fn ($section): string => $section->name, $form->root->sections));
        $phones = $form->root->sections[0]->sections[0];
        self::assertSame(['phone', 'profile', FormNodeMode::Many, 1, true], [$phones->object, $phones->via, $phones->mode, $phones->min, $phones->inline]);
        self::assertFalse($form->root->sections[1]->inline);
        self::assertSame(['account', 'profile', 'phone', 'qualification'], $form->root->objects());
        self::assertStringContainsString('I accept the terms', $form->source);
    }

    public function testCreatesTheWholeTreeInOneGo(): void
    {
        $id = $this->forms->save('registration', null, $this->registration());

        $saved = $this->forms->read('registration', $id);
        self::assertNotNull($saved);
        self::assertSame('mario', $saved->record->get('username'));
        self::assertTrue($saved->record->get('active'));
        $profile = self::one($saved, 'profile');
        self::assertSame('Milano', $profile->record->get('city'));
        self::assertSame($id, $profile->record->get('account'));
        $phones = self::rows($profile, 'phones');
        self::assertSame(['333 123 4567', '02 123456'], array_map(static fn (CompositeRecord $phone): mixed => $phone->record->get('number'), $phones));
        self::assertSame(['mobile', 'home'], array_map(static fn (CompositeRecord $phone): mixed => $phone->record->get('type'), $phones));
        // The empty qualification row was skipped.
        self::assertCount(1, self::rows($saved, 'qualifications'));
    }

    public function testReportsEveryErrorAtOnceAndWritesNothing(): void
    {
        $input = $this->registration(
            account: ['email' => 'not an email', 'firstname' => ''],
            terms: false,
            profile: ['zipcode' => '123'],
            phones: [],
        );

        try {
            $this->forms->save('registration', null, $input);
            self::fail('The form must be refused.');
        } catch (CompositeValidationFailed $e) {
            self::assertSame(['This value is not a valid email address.'], $e->errors['values.email']);
            self::assertSame(['This value is required.'], $e->errors['values.firstname']);
            self::assertSame(['This box must be checked.'], $e->errors['service.terms']);
            self::assertSame(['This value is not valid.'], $e->errors['sections.profile.values.zipcode']);
            self::assertSame(['Add at least one row.'], $e->errors['sections.profile.sections.phones']);
        }
        self::assertSame([], $this->finder->latest('account'));
        self::assertSame([], $this->finder->latest('profile'));
    }

    public function testTheFormMayRequireMoreThanTheObject(): void
    {
        try {
            $this->forms->save('registration', null, $this->registration(profile: ['city' => '']));
            self::fail('The city is required by the form.');
        } catch (CompositeValidationFailed $e) {
            self::assertSame(['This value is required.'], $e->errors['sections.profile.values.city']);
        }

        // The object itself does not require it: a plain record of it saves without a city.
        $id = $this->forms->save('registration', null, $this->registration());
        $profile = self::one($this->read($id), 'profile');
        self::getContainer()->get(RecordManagerInterface::class)->update($profile->record->id, new RecordInput('profile', ['city' => null]));
        self::assertNull($this->finder->find('profile', $profile->record->id)?->get('city'));
    }

    public function testWhatTheFormRequiresFollowsTheConditions(): void
    {
        // The city applies to a profile only when the gender is given as "f" (a test rule).
        self::getContainer()->get(MetadataManagerInterface::class)->configureFieldCondition('profile', 'city', 'gender', ['f']);

        $id = $this->forms->save('registration', null, $this->registration(profile: ['gender' => 'm', 'city' => '']));
        self::assertNull(self::one($this->read($id), 'profile')->record->get('city'));

        $this->expectException(CompositeValidationFailed::class);
        $this->forms->save('registration', null, $this->registration(account: ['username' => 'anna', 'email' => 'anna@example.com'], profile: ['gender' => 'f', 'city' => '']));
    }

    public function testEditingUpdatesAddsAndRemovesRows(): void
    {
        $id = $this->forms->save('registration', null, $this->registration());
        $saved = $this->forms->read('registration', $id);
        self::assertNotNull($saved);
        [$mobile, $home] = self::rows(self::one($saved, 'profile'), 'phones');

        // Keep the mobile (changed), drop the home phone, add an office one; password left empty.
        $this->forms->save('registration', $id, new CompositeNodeInput(
            values: ['firstname' => 'Mario Luigi', 'password' => ''],
            service: ['terms' => true],
            sections: ['profile' => new CompositeNodeInput(values: ['city' => 'Torino'], sections: ['phones' => [
                0 => new CompositeNodeInput(id: $mobile->record->id, values: ['number' => '333 765 4321']),
                5 => new CompositeNodeInput(values: ['number' => '011 555 555', 'type' => 'office']),
            ]])],
        ));

        $saved = $this->forms->read('registration', $id);
        self::assertNotNull($saved);
        self::assertSame('Mario Luigi', $saved->record->get('firstname'));
        $profile = self::one($saved, 'profile');
        self::assertSame('Torino', $profile->record->get('city'));
        self::assertSame(['333 765 4321', '011 555 555'], array_map(static fn (CompositeRecord $phone): mixed => $phone->record->get('number'), self::rows($profile, 'phones')));
        self::assertNull($this->finder->find('phone', $home->record->id));
    }

    public function testAServiceFieldAskedAtCreationOnlyIsNotAskedAgain(): void
    {
        self::getContainer()->get(MetadataManagerInterface::class)->saveForm('registration', str_replace(
            ['required: true }', '{ name: qualifications, label: Qualifications, object: qualification, via: account, mode: many }'],
            ['required: true, ask: create }', '{ name: qualifications, label: Qualifications, object: qualification, via: account, mode: many, service_fields: [{ name: earned, type: boolean, label: I earned it, required: true, ask: create }] }'],
            self::REGISTRATION_FORM,
        ));
        $root = self::getContainer()->get(MetadataRegistryInterface::class)->getForm('registration')->root;
        self::assertTrue($root->serviceFields[0]->isAskedFor(true));
        self::assertFalse($root->serviceFields[0]->isAskedFor(false));

        // A new account is asked both: the terms, and "earned" on its new qualification.
        try {
            $this->forms->save('registration', null, $this->registration(terms: false));
            self::fail('The form must be refused.');
        } catch (CompositeValidationFailed $e) {
            self::assertSame(['This box must be checked.'], $e->errors['service.terms']);
            self::assertSame(['This value is required.'], $e->errors['sections.qualifications.0.service.earned']);
        }
        $input = $this->registration();
        $qualifications = $input->sections['qualifications'];
        self::assertIsArray($qualifications);
        $qualifications[0] = new CompositeNodeInput(values: $qualifications[0]->values, service: ['earned' => true]);
        $id = $this->forms->save('registration', null, new CompositeNodeInput(values: $input->values, service: $input->service, sections: ['qualifications' => $qualifications] + $input->sections));
        [$qualification] = self::rows($this->read($id), 'qualifications');

        // Editing it asks neither again, but a row added meanwhile is new: it is asked "earned".
        $edit = static fn (bool $earned): CompositeNodeInput => new CompositeNodeInput(
            values: ['password' => ''],
            sections: ['qualifications' => [0 => new CompositeNodeInput(id: $qualification->record->id, values: ['year' => 2020]), 4 => new CompositeNodeInput(values: ['title' => 'Physics', 'year' => 2021], service: $earned ? ['earned' => true] : [])]],
        );
        try {
            $this->forms->save('registration', $id, $edit(false));
            self::fail('The new row must be refused.');
        } catch (CompositeValidationFailed $e) {
            self::assertSame(['sections.qualifications.4.service.earned'], array_keys($e->errors));
        }
        $this->forms->save('registration', $id, $edit(true));
        self::assertCount(2, self::rows($this->read($id), 'qualifications'));
    }

    public function testRefusesAnUnknownAsk(): void
    {
        try {
            self::getContainer()->get(MetadataManagerInterface::class)->saveForm('registration', str_replace('required: true }', 'required: true, ask: sometimes }', self::REGISTRATION_FORM));
            self::fail('The definition must be refused.');
        } catch (MetadataInputInvalid $e) {
            self::assertContains('(root) > terms: "ask" must be one of always, create.', $e->errors['definition']);
        }
    }

    public function testErrorsOfRowsArePathedByTheirKey(): void
    {
        $input = $this->registration(phones: [7 => ['number' => 'call me']]);

        try {
            $this->forms->save('registration', null, $input);
            self::fail('The form must be refused.');
        } catch (CompositeValidationFailed $e) {
            self::assertArrayHasKey('sections.profile.sections.phones.7.values.number', $e->errors);
        }
    }

    public function testRejectsAnInvalidDefinitionWithEveryError(): void
    {
        try {
            self::getContainer()->get(MetadataManagerInterface::class)->saveForm('broken', <<<'YAML'
                label: Broken
                object: account
                fields: [username, nickname]
                required: [lastname]
                sections:
                  - { name: phones, label: Phones, object: phone, via: profile, mode: many }
                  - { name: ghosts, label: Ghosts, object: ghost, via: account }
                  - { name: profile, label: Profile, object: profile, via: account, mode: one, min: 1, inline: yes please }
                YAML);
            self::fail('The definition must be refused.');
        } catch (MetadataInputInvalid $e) {
            $errors = implode("\n", $e->errors['definition']);
            self::assertStringContainsString('(root): object "account" has no field "nickname".', $errors);
            self::assertStringContainsString('(root): "email" is required by object "account": list it in "fields".', $errors);
            self::assertStringContainsString('(root): "required" may only name fields the form shows, "lastname" is not one.', $errors);
            self::assertStringContainsString('phones: "profile" is not a relation field of "phone" pointing at "account" (it has none: add one first).', $errors);
            self::assertStringContainsString('ghosts: "object" must name an existing object, "ghost" does not exist.', $errors);
            self::assertStringContainsString('profile: "min" and "max" apply to "mode: many" only.', $errors);
            self::assertStringContainsString('profile: "inline" must be true or false.', $errors);
        }
    }

    private function read(string $id): CompositeRecord
    {
        $record = $this->forms->read('registration', $id);
        self::assertNotNull($record);

        return $record;
    }

    private static function one(CompositeRecord $parent, string $section): CompositeRecord
    {
        $record = $parent->sections[$section] ?? null;
        self::assertInstanceOf(CompositeRecord::class, $record);

        return $record;
    }

    /**
     * @return list<CompositeRecord>
     */
    private static function rows(CompositeRecord $parent, string $section): array
    {
        $rows = $parent->sections[$section] ?? null;
        self::assertIsArray($rows);

        return $rows;
    }

    /**
     * @param array<string, mixed>                  $account
     * @param array<string, mixed>                  $profile
     * @param array<int, array<string, mixed>>|null $phones
     */
    private function registration(array $account = [], bool $terms = true, array $profile = [], ?array $phones = null): CompositeNodeInput
    {
        $phones ??= [['number' => '333 123 4567'], ['number' => '02 123456', 'type' => 'home']];

        return new CompositeNodeInput(
            values: $account + ['username' => 'mario', 'email' => 'mario@example.com', 'firstname' => 'Mario', 'lastname' => 'Rossi', 'password' => 'secret123', 'active' => true],
            service: ['terms' => $terms],
            sections: [
                'profile' => new CompositeNodeInput(
                    values: $profile + ['gender' => 'm', 'city' => 'Milano', 'zipcode' => '20121'],
                    sections: ['phones' => array_map(static fn (array $phone): CompositeNodeInput => new CompositeNodeInput(values: $phone + ['prefix' => '+39', 'type' => 'mobile']), $phones)],
                ),
                'qualifications' => [
                    new CompositeNodeInput(values: ['title' => 'Computer engineering', 'year' => 2019]),
                    // Left as the form shows it: skipped.
                    new CompositeNodeInput(values: ['title' => '', 'year' => '']),
                ],
            ],
        );
    }
}
