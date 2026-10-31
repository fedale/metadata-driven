<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Metadata;

use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Tests\Support\CreatesRegistrationForm;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The layouts of a composite form: sanitized when the form is saved, their tokens checked
 * against the fields, service fields and sections of their node.
 */
final class FormLayoutTest extends KernelTestCase
{
    use CreatesRegistrationForm;

    protected function setUp(): void
    {
        self::createRegistrationForm(self::getContainer());
    }

    public function testStoresTheLayoutsSanitized(): void
    {
        $this->save(<<<'YAML'
            label: Registration
            object: account
            fields: [username, email, firstname, lastname, password]
            service_fields:
              - { name: terms, type: boolean, label: I accept the terms }
            layout: '<div class="md-columns" onclick="steal()">{username} { terms }<script>alert(1)</script></div>{profile}'
            sections:
              - { name: profile, label: Profile, object: profile, via: account, layout: '{city}' }
            YAML);

        $root = self::getContainer()->get(MetadataRegistryInterface::class)->getForm('layout')->root;
        self::assertSame('<div class="md-columns">{username} { terms }</div>{profile}', $root->layout);
        self::assertSame(['username', 'terms', 'profile'], $root->placed());
        self::assertSame('{city}', $root->sections[0]->layout);
    }

    public function testReportsEveryErrorOfTheLayouts(): void
    {
        try {
            $this->save(<<<'YAML'
                label: Registration
                object: account
                fields: [username, email, firstname, lastname, password, active]
                layout: '<p class="{email}">{username} {nickname} {username}</p>'
                sections:
                  - name: profile
                    label: Profile
                    object: profile
                    via: account
                    sections:
                      - { name: phones, label: Phones, object: phone, via: profile, mode: many, inline: true, layout: '{number}' }
                  - name: active
                    label: Qualifications
                    object: qualification
                    via: account
                    mode: many
                    layout: '{title} {account}'
                YAML);
            self::fail('The layouts are invalid.');
        } catch (MetadataInputInvalid $e) {
            $errors = $e->errors['definition'];
        }

        self::assertContains('(root): "active" names both a field and a section: rename one, a {active} token would be ambiguous.', $errors);
        self::assertContains('(root): the layout places {nickname}, which is no field, service field or section of this node (username, email, firstname, lastname, password, active, profile).', $errors);
        self::assertContains('(root): the layout places {username} more than once.', $errors);
        self::assertContains('(root): {name} tokens go in the text of the layout, not inside a tag or an attribute.', $errors);
        self::assertContains('profile.phones: "layout" and "inline" exclude each other: an inline row lays its fields out by itself.', $errors);
        // The "via" field is set by the form: no token places it.
        self::assertContains('active: the layout places {account}, which is no field, service field or section of this node (title, year).', $errors);
    }

    private function save(string $definition): void
    {
        self::getContainer()->get(MetadataManagerInterface::class)->saveForm('layout', $definition);
    }
}
