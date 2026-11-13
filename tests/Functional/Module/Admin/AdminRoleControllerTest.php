<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Admin;

use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\RecordAction;
use App\Module\Access\Contract\TenantRolesInterface;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Tests\Support\SignsIn;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Fedale\RbacBundle\Enum\AuthItemType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * /admin/roles: what each role of the tenant gives (docs/internal/tenant-admin-ui-plan.md,
 * part D), and no way to more power.
 */
final class AdminRoleControllerTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::getContainer()->get(MetadataManagerInterface::class)->createObject('pet', 'Pet', 'Pets');
    }

    public function testCreatesEditsAndDeletesARole(): void
    {
        self::signInAsSuperAdmin($this->client);

        $this->client->request('GET', '/admin/roles');
        $this->client->submitForm('Create', ['new_role[name]' => 'pet reader']);
        self::assertResponseStatusCodeSame(422, 'A malformed name.');

        $this->submitPermissions('Create', ['new_role[name]' => 'ROLE_PET_READER', 'new_role[description]' => 'Reads the pets'], [Permissions::record('pet', RecordAction::Read)]);
        self::assertSame([Permissions::record('pet', RecordAction::Read)], $this->roles()->permissionsOf('ROLE_PET_READER'));

        $this->client->request('GET', '/admin/roles/ROLE_PET_READER');
        $this->submitPermissions('Save', [], [Permissions::record('pet', RecordAction::Read), Permissions::record('pet', RecordAction::Create)]);
        self::assertSame([Permissions::record('pet', RecordAction::Create), Permissions::record('pet', RecordAction::Read)], $this->roles()->permissionsOf('ROLE_PET_READER'));

        $crawler = $this->client->request('GET', '/admin/roles');
        $this->client->submit($crawler->filter('form[name="delete_role_pet_reader"]')->form());
        self::assertArrayNotHasKey('ROLE_PET_READER', $this->roles()->roles());
    }

    public function testARoleHeldBySomeoneIsNotDeleted(): void
    {
        $rbac = self::getContainer()->get(RbacManagerInterface::class);
        $rbac->addItem('ROLE_PET_READER', AuthItemType::ROLE);
        self::createUser('bruno@example.com');
        $rbac->assign('bruno@example.com', 'ROLE_PET_READER');
        self::signInAsSuperAdmin($this->client);

        $crawler = $this->client->request('GET', '/admin/roles');
        self::assertCount(0, $crawler->filter('form[name="delete_role_pet_reader"]'));
        $this->client->request('POST', '/admin/roles/ROLE_PET_READER/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheSuperAdminRoleIsNeverEditedHere(): void
    {
        self::signInAsSuperAdmin($this->client);

        $this->client->request('GET', '/admin/roles/ROLE_SUPER_ADMIN');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * An admin of the roles who isn't a super admin: only what they hold, and nothing of those
     * above them.
     */
    public function testALesserAdminGivesOnlyWhatTheyHold(): void
    {
        $rbac = self::getContainer()->get(RbacManagerInterface::class);
        $rbac->addItem('ROLE_ROLES', AuthItemType::ROLE);
        $rbac->addItem('ROLE_EDITOR', AuthItemType::ROLE);
        self::signIn($this->client, 'boss@example.com', [Permissions::SUPER_ADMIN_ROLE, 'ROLE_EDITOR']);
        $rbac->addChild('ROLE_ROLES', Permissions::ROLES);
        $rbac->addChild('ROLE_ROLES', Permissions::record('pet', RecordAction::Read));
        $rbac->addChild('ROLE_EDITOR', Permissions::record('pet', RecordAction::Read));
        $rbac->addItem('ROLE_DESIGNER', AuthItemType::ROLE);
        $rbac->addChild('ROLE_DESIGNER', Permissions::ADMINISTER);
        self::signIn($this->client, 'lena@example.com', ['ROLE_ROLES']);

        $crawler = $this->client->request('GET', '/admin/roles');
        $offered = $crawler->filter('form[name="new_role"] input[type="checkbox"]')->each(static fn ($input): string => (string) $input->attr('value'));
        self::assertEqualsCanonicalizing([Permissions::ROLES, Permissions::record('pet', RecordAction::Read)], $offered);

        // A permission she doesn't hold, forged in: no valid form, no role.
        $token = (string) $crawler->filter('form[name="new_role"] input[name="new_role[_token]"]')->attr('value');
        $this->client->request('POST', '/admin/roles', ['new_role' => ['name' => 'ROLE_SNEAKY', 'permissions' => [Permissions::ADMINISTER], '_token' => $token]]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayNotHasKey('ROLE_SNEAKY', $this->roles()->roles());

        // A role giving what she doesn't hold, and one held by the boss above her.
        foreach (['ROLE_DESIGNER', 'ROLE_EDITOR'] as $role) {
            $this->client->request('GET', '/admin/roles/'.$role);
            self::assertResponseStatusCodeSame(403, $role);
        }
    }

    /**
     * Through the hierarchy too: a role within one held above is out of reach, and a role within
     * another is not deleted from here (it would change the other).
     */
    public function testARoleWithinAnotherIsGuardedAsTheOther(): void
    {
        $rbac = self::getContainer()->get(RbacManagerInterface::class);
        $rbac->addItem('ROLE_ROLES', AuthItemType::ROLE);
        $rbac->addItem('ROLE_READER', AuthItemType::ROLE);
        $rbac->addItem('ROLE_MANAGER', AuthItemType::ROLE);
        self::signIn($this->client, 'boss@example.com', [Permissions::SUPER_ADMIN_ROLE, 'ROLE_MANAGER']);
        $rbac->addChild('ROLE_ROLES', Permissions::ROLES);
        $rbac->addChild('ROLE_ROLES', Permissions::record('pet', RecordAction::Read));
        $rbac->addChild('ROLE_READER', Permissions::record('pet', RecordAction::Read));
        $rbac->addChild('ROLE_MANAGER', 'ROLE_READER');            // the boss reads through it
        self::signIn($this->client, 'lena@example.com', ['ROLE_ROLES']);

        $crawler = $this->client->request('GET', '/admin/roles');
        self::assertCount(0, $crawler->filter('form[name="delete_role_reader"]'));
        $this->client->request('GET', '/admin/roles/ROLE_READER');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/roles/ROLE_READER/delete');
        self::assertResponseStatusCodeSame(403);
        self::assertArrayHasKey('ROLE_READER', $this->roles()->roles());
    }

    /**
     * Holders who aren't members (removed, keeping their roles; or given a role ahead of their
     * account) count all the same.
     */
    public function testARoleHeldBySomeoneOutsideTheMembersIsGuardedToo(): void
    {
        $rbac = self::getContainer()->get(RbacManagerInterface::class);
        $rbac->addItem('ROLE_ROLES', AuthItemType::ROLE);
        $rbac->addItem('ROLE_READER', AuthItemType::ROLE);
        self::signIn($this->client, 'lena@example.com', ['ROLE_ROLES']);
        $rbac->addChild('ROLE_ROLES', Permissions::ROLES);
        $rbac->addChild('ROLE_ROLES', Permissions::record('pet', RecordAction::Read));
        $rbac->addChild('ROLE_READER', Permissions::record('pet', RecordAction::Read));
        // No member: an email given roles ahead of its account, one of them beyond Lena.
        $rbac->assign('former@example.com', 'ROLE_READER');
        $rbac->assign('former@example.com', Permissions::SUPER_ADMIN_ROLE);

        $this->client->request('GET', '/admin/roles/ROLE_READER');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/roles/ROLE_READER/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testNeedsThePermissionToManageRoles(): void
    {
        self::signIn($this->client, 'editor@example.com', [Permissions::ADMINISTER]);

        $this->client->request('GET', '/admin/roles');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Submits the form of the button, ticking exactly these permissions.
     *
     * @param array<string, string> $values
     * @param list<string>          $permissions
     */
    private function submitPermissions(string $button, array $values, array $permissions): void
    {
        $form = $this->client->getCrawler()->selectButton($button)->form($values);
        foreach ($form->all() as $field) {
            if ($field instanceof ChoiceFormField && 'checkbox' === $field->getType()) {
                \in_array($field->availableOptionValues()[0] ?? null, $permissions, true) ? $field->tick() : $field->untick();
            }
        }
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/roles', 303);
        $this->client->followRedirect();
    }

    private function roles(): TenantRolesInterface
    {
        return self::getContainer()->get(TenantRolesInterface::class);
    }
}
