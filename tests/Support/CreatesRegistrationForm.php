<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Command\CreateObjectCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Metadata\Application\Handler\CreateObjectHandler;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The user registration of the specification: an account, its profile (one-to-one), the
 * phones of the profile and the qualifications of the account (one-to-many), in one form.
 */
trait CreatesRegistrationForm
{
    public const string REGISTRATION_FORM = <<<'YAML'
        label: User registration
        object: account
        fields: [username, email, firstname, lastname, password, active]
        service_fields:
          - { name: terms, type: boolean, label: I accept the terms, required: true }
        sections:
          - name: profile
            label: Profile
            object: profile
            via: account
            required: [city]           # required by this form, not by the profile object
            sections:
              - { name: phones, label: Phones, object: phone, via: profile, mode: many, min: 1, inline: true }
          - { name: qualifications, label: Qualifications, object: qualification, via: account, mode: many }
        YAML;

    private static function createRegistrationForm(ContainerInterface $container): void
    {
        $object = $container->get(CreateObjectHandler::class);
        $field = $container->get(CreateFieldHandler::class);
        $relation = static fn (string $target, string $inverse): array => ['target' => $target, 'onDelete' => 'cascade', 'inverseName' => $inverse];

        $object(new CreateObjectCommand('account', 'Account', 'Accounts'));
        $field(new CreateFieldCommand('account', 'username', 'text', 'Username', isRequired: true, isUnique: true));
        $field(new CreateFieldCommand('account', 'email', 'email', 'Email', isRequired: true, isUnique: true));
        $field(new CreateFieldCommand('account', 'firstname', 'text', 'First name', isRequired: true));
        $field(new CreateFieldCommand('account', 'lastname', 'text', 'Last name', isRequired: true));
        $field(new CreateFieldCommand('account', 'password', 'password', 'Password', isRequired: true, settings: ['minLength' => 8, 'confirm' => true]));
        $field(new CreateFieldCommand('account', 'active', 'boolean', 'Active', settings: ['default' => true, 'display' => 'switch']));

        $object(new CreateObjectCommand('profile', 'Profile', 'Profiles'));
        $field(new CreateFieldCommand('profile', 'account', 'relation', 'Account', isUnique: true, settings: $relation('account', 'profile')));
        $field(new CreateFieldCommand('profile', 'gender', 'select', 'Gender', settings: ['display' => 'radio', 'options' => [
            ['value' => 'm', 'label' => 'Male'], ['value' => 'f', 'label' => 'Female'], ['value' => 'x', 'label' => 'Non-binary'], ['value' => 'n', 'label' => 'Rather not say'],
        ]]));
        $field(new CreateFieldCommand('profile', 'birthdate', 'date', 'Birth date'));
        $field(new CreateFieldCommand('profile', 'city', 'text', 'City'));
        $field(new CreateFieldCommand('profile', 'zipcode', 'text', 'Postal code', settings: ['maxLength' => 5, 'pattern' => '\d{5}']));

        $object(new CreateObjectCommand('phone', 'Phone', 'Phones'));
        $field(new CreateFieldCommand('phone', 'profile', 'relation', 'Profile', isRequired: true, settings: $relation('profile', 'phones')));
        $field(new CreateFieldCommand('phone', 'prefix', 'select', 'Prefix', settings: ['default' => '+39', 'options' => [['value' => '+39', 'label' => '+39 IT'], ['value' => '+41', 'label' => '+41 CH']]]));
        $field(new CreateFieldCommand('phone', 'number', 'tel', 'Number', isRequired: true));
        $field(new CreateFieldCommand('phone', 'type', 'select', 'Type', settings: ['default' => 'mobile', 'options' => [['value' => 'mobile', 'label' => 'Mobile'], ['value' => 'home', 'label' => 'Home'], ['value' => 'office', 'label' => 'Office']]]));

        $object(new CreateObjectCommand('qualification', 'Qualification', 'Qualifications'));
        $field(new CreateFieldCommand('qualification', 'account', 'relation', 'Account', isRequired: true, settings: $relation('account', 'qualifications')));
        $field(new CreateFieldCommand('qualification', 'title', 'text', 'Title', isRequired: true));
        $field(new CreateFieldCommand('qualification', 'year', 'number', 'Year', settings: ['min' => 1950, 'max' => 2100]));

        $container->get(MetadataManagerInterface::class)->saveForm('registration', self::REGISTRATION_FORM);
    }
}
