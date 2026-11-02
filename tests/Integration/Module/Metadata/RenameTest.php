<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Metadata;

use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Domain\Repository\TranslationRepository;
use App\Module\Record\Contract\CompositeRecordManagerInterface;
use App\Module\Record\Contract\Dto\CompositeNodeInput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Schema\Contract\SchemaSynchronizerInterface;
use App\Tests\Support\CreatesPlaces;
use App\Tests\Support\CreatesRegistrationForm;
use Doctrine\DBAL\Connection;
use Fedale\RbacBundle\Contract\ItemStorageInterface;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Fedale\RbacBundle\Enum\AuthItemType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Renaming an object or a field renames everything named after it: the table or the column
 * (records kept), the indexes and constraints, the relations, conditions and cascades, the
 * views and composite forms written by hand, the translations and the permissions.
 */
final class RenameTest extends KernelTestCase
{
    use CreatesPlaces;
    use CreatesRegistrationForm;

    private MetadataManagerInterface $manager;
    private MetadataRegistryInterface $metadata;

    protected function setUp(): void
    {
        $this->manager = self::getContainer()->get(MetadataManagerInterface::class);
        $this->metadata = self::getContainer()->get(MetadataRegistryInterface::class);
    }

    public function testRenamesAnObjectAndWhatIsNamedAfterIt(): void
    {
        self::createRegistrationForm(self::getContainer());
        $accountId = $this->register();
        $profileId = $this->profileOf($accountId);
        $this->manager->translate('it', 'object.profile.singular', 'Profilo');
        $this->manager->translate('it', 'field.profile.city', 'Città');
        $rbac = self::getContainer()->get(RbacManagerInterface::class);
        $rbac->addItem('ROLE_CLERK', AuthItemType::ROLE);
        $rbac->addChild('ROLE_CLERK', 'record.profile.read');
        $rbac->assign('someone', 'record.profile.update');

        self::assertSame(['composite form "registration"'], $this->manager->renameObject('profile', 'person'));

        self::assertFalse($this->metadata->hasObject('profile'));
        $person = $this->metadata->getObject('person');
        self::assertSame('c_person', $person->tableName);
        // The records stay, in the renamed table; the relation pointing at it follows.
        self::assertSame('Milano', self::getContainer()->get(RecordFinderInterface::class)->find('person', $profileId)?->get('city'));
        self::assertSame('person', $this->metadata->getObject('phone')->field('profile')->relation()?->target);
        self::assertSame(['c_person_pkey', 'fk_c_person_account'], $this->constraintNames('c_person'));
        // The trigram indexes of the search are built again under the new name.
        self::assertSame(['c_person_pkey', 'trgm_c_person_city', 'trgm_c_person_zipcode', 'uniq_c_person_account'], $this->indexNames('c_person'));
        $schema = self::getContainer()->get(SchemaSynchronizerInterface::class);
        foreach (['person', 'phone', 'account'] as $object) {
            self::assertTrue($schema->plan($object)->isEmpty(), $object);
        }
        // The form written by hand names the new object, and still reads the records.
        $form = $this->metadata->getForm('registration');
        self::assertSame('person', $form->root->sections[0]->object);
        self::assertStringContainsString('object: person', $form->source);
        self::assertNotNull(self::getContainer()->get(CompositeRecordManagerInterface::class)->read('registration', $accountId)?->sections['profile']);
        // Translations and permissions move, with the roles and users holding them.
        $texts = self::getContainer()->get(TranslationRepository::class);
        self::assertSame('Profilo', $texts->textsOf('it')['object.person.singular'] ?? null);
        self::assertSame('Città', $texts->textsOf('it')['field.person.city'] ?? null);
        self::assertArrayNotHasKey('object.profile.singular', $texts->textsOf('it'));
        $items = self::getContainer()->get(ItemStorageInterface::class);
        self::assertNull($items->getItem('record.profile.read'));
        self::assertContains('record.person.read', $items->getChildren('ROLE_CLERK'));
        self::assertSame(['record.person.update'], $this->connection()->fetchFirstColumn("SELECT item_name FROM auth_assignment WHERE user_id = 'someone'"));
    }

    public function testRenamesAFieldAndWhatNamesIt(): void
    {
        self::createRegistrationForm(self::getContainer());
        $accountId = $this->register();
        $this->manager->translate('en', 'text.sign_in', 'Sign in');
        $this->manager->saveView('account', ViewKind::Form, 'compact', '<fieldset><legend>{t:sign_in}</legend>{ username } {email}</fieldset>');
        $this->manager->saveView('account', ViewKind::Grid, 'list', "# the people\ncolumns:\n  - username\n  - { name: who, template: '{firstname} {username}' }\nsort: { username: asc }\n");
        $this->manager->saveView('account', ViewKind::Grid, 'names', "# untouched\ncolumns: [firstname, lastname]\n");
        $this->manager->configureLabel('account', 'username');
        $this->manager->configureSearch('account', null, ['username', 'email']);
        $this->manager->configureFieldCondition('account', 'lastname', 'active', ['true']);
        $this->manager->translate('it', 'field.account.username', 'Utente');

        $rewritten = $this->manager->renameField('account', 'username', 'login');

        self::assertSame(['form view "compact"', 'grid view "list"', 'composite form "registration"'], $rewritten);
        $account = $this->metadata->getObject('account');
        self::assertFalse($account->hasField('username'));
        self::assertSame('login', $account->field('login')->columnName);
        self::assertSame('mario', self::getContainer()->get(RecordFinderInterface::class)->find('account', $accountId)?->get('login'));
        self::assertContains('uniq_c_account_login', $this->indexNames('c_account'));
        self::assertTrue(self::getContainer()->get(SchemaSynchronizerInterface::class)->plan('account')->isEmpty());
        self::assertSame('login', $account->labelField);
        self::assertSame(['login', 'email'], $account->searchFields);

        $views = [];
        foreach ($account->views as $view) {
            $views[$view->name] = $view;
        }
        // HTML keeps its text, only the tokens change; a YAML definition is dumped again.
        self::assertSame('<fieldset><legend>{t:sign_in}</legend>{login} {email}</fieldset>', $views['compact']->template);
        self::assertSame(['login', 'who'], array_map(static fn ($column): string => $column->key, $views['list']->grid->columns ?? []));
        self::assertSame(['firstname', 'login'], $views['list']->grid->columns[1]->sources ?? null);
        self::assertSame(['login' => 'asc'], $views['list']->grid?->sort);
        self::assertStringNotContainsString('# the people', $views['list']->template);
        self::assertStringStartsWith('# untouched', $views['names']->template);

        $form = $this->metadata->getForm('registration');
        self::assertContains('login', $form->root->fields ?? []);
        self::assertSame('Utente', self::getContainer()->get(TranslationRepository::class)->textsOf('it')['field.account.login'] ?? null);
    }

    public function testRenamingAFieldFollowsTheConditionsAndTheCascades(): void
    {
        self::createPlaces(self::getContainer());

        // venue.province depends on venue.region, through province.region.
        $this->manager->renameField('venue', 'region', 'zone');
        $this->manager->renameField('province', 'region', 'in_region');

        $province = $this->metadata->getObject('venue')->field('province')->relation();
        self::assertNotNull($province);
        self::assertSame('zone', $province->dependsOn);
        self::assertSame('in_region', $province->dependsVia);
        self::assertContains('fk_c_province_in_region', $this->constraintNames('c_province'));
        self::assertContains('idx_c_province_in_region', $this->indexNames('c_province'));
        self::assertTrue(self::getContainer()->get(SchemaSynchronizerInterface::class)->plan('province')->isEmpty());
    }

    public function testRefusesANameTakenOrReserved(): void
    {
        self::createRegistrationForm(self::getContainer());

        foreach ([
            fn () => $this->manager->renameObject('profile', 'account'),
            fn () => $this->manager->renameField('account', 'email', 'username'),
            fn () => $this->manager->renameField('account', 'email', 'created_at'),
        ] as $index => $rename) {
            try {
                $rename();
                self::fail(\sprintf('Rename #%d must be refused.', $index));
            } catch (MetadataException) {
            }
        }
        try {
            $this->manager->renameObject('profile', 'Bad Name');
            self::fail('The name must be refused.');
        } catch (MetadataInputInvalid $e) {
            self::assertArrayHasKey('newName', $e->errors);
        }
        self::assertTrue($this->metadata->hasObject('profile'));
    }

    private function register(): string
    {
        return self::getContainer()->get(CompositeRecordManagerInterface::class)->save('registration', null, new CompositeNodeInput(
            values: ['username' => 'mario', 'email' => 'mario@example.com', 'firstname' => 'Mario', 'lastname' => 'Rossi', 'password' => 'secret123', 'active' => true],
            service: ['terms' => true],
            sections: [
                'profile' => new CompositeNodeInput(values: ['city' => 'Milano'], sections: ['phones' => [new CompositeNodeInput(values: ['number' => '333 123 4567'])]]),
                'qualifications' => [],
            ],
        ));
    }

    private function profileOf(string $accountId): string
    {
        $profile = self::getContainer()->get(RecordFinderInterface::class)->findReferencing('profile', 'account', $accountId)[0] ?? null;
        self::assertNotNull($profile);

        return $profile->id;
    }

    /**
     * @return list<string>
     */
    private function constraintNames(string $table): array
    {
        /** @var list<string> $names */
        $names = $this->connection()->fetchFirstColumn("SELECT conname FROM pg_constraint WHERE conrelid = ('data.' || quote_ident(?))::regclass ORDER BY conname", [$table]);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        /** @var list<string> $names */
        $names = $this->connection()->fetchFirstColumn("SELECT indexname FROM pg_indexes WHERE schemaname = 'data' AND tablename = ? ORDER BY indexname", [$table]);

        return $names;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
