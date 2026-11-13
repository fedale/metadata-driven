<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Admin;

use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\TenantRolesInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Shared\Infrastructure\Tenant\TenantRegistry;
use App\Shared\Tenant\Tenant;
use App\Tests\Support\CreatesTenants;
use App\Tests\Support\SignsIn;
use Doctrine\DBAL\Connection;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Fedale\RbacBundle\Enum\AuthItemType;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * /admin/members: who may sign in to the tenant, with which of its roles
 * (docs/internal/tenant-admin-ui-plan.md, part A).
 */
final class AdminMemberControllerTest extends WebTestCase
{
    use CreatesTenants;
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The limiters keep their count in a cache that outlives a test (a request reboots the
        // kernel, so an array cache would forget at once): each test starts from none.
        $limits = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $limits);
        $limits->clear();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testListsTheMembersOfTheTenantOnly(): void
    {
        self::signInAsSuperAdmin($this->client);
        self::createUser('bruno@example.com');
        $globex = self::createTenant(self::getContainer(), 'globex', 'Globex');
        self::useTenant(self::getContainer(), $globex);
        self::createUser('carla@example.com');                     // Globex's only

        $this->client->request('GET', '/admin/members');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'admin@example.com');
        self::assertSelectorTextContains('main', 'bruno@example.com');
        self::assertSelectorTextNotContains('main', 'carla@example.com');
    }

    /**
     * The same answer for an email with an account and one without, and nothing of the account:
     * an invitation, its link shown once.
     */
    public function testAnInvitationTellsNothingOfTheAccount(): void
    {
        self::signInAsSuperAdmin($this->client);
        $globex = self::createTenant(self::getContainer(), 'globex', 'Globex');
        self::useTenant(self::getContainer(), $globex);
        self::getContainer()->get(UserManagerInterface::class)->createUser('carla@example.com', self::PASSWORD, 'Carla', 'Bruni');
        self::useTenant(self::getContainer(), $this->tenant('default'));

        $carla = $this->invite('carla@example.com');
        $flashCarla = $this->client->getCrawler()->filter('.md-flash--success')->first()->text();
        $dora = $this->invite('dora@example.com');
        $flashDora = $this->client->getCrawler()->filter('.md-flash--success')->first()->text();

        self::assertSame(str_replace('carla', 'dora', $flashCarla), $flashDora);
        self::assertSelectorTextNotContains('main', 'Carla');
        self::assertNotSame($carla, $dora);
        self::assertSame(['admin@example.com'], $this->members(), 'Nobody is a member before accepting.');
        self::assertSelectorTextContains('main', 'carla@example.com');
    }

    public function testANewAccountAcceptsWithAPasswordOfItsOwn(): void
    {
        self::signInAsSuperAdmin($this->client);
        $link = $this->invite('dora@example.com');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'dora@example.com');
        $this->client->submitForm('Join', ['invitation[password]' => self::PASSWORD, 'invitation[first_name]' => 'Dora', 'invitation[last_name]' => 'Maar']);

        self::assertResponseRedirects('/invitation/welcome', 303);
        $this->client->followRedirect();
        self::assertResponseRedirects('/', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-topbar__user', 'Dora Maar');
        self::assertContains('dora@example.com', $this->members());
        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(404, 'Accepted: used up.');
    }

    public function testAnExistingAccountAcceptsWithItsPassword(): void
    {
        self::signInAsSuperAdmin($this->client);
        $globex = self::createTenant(self::getContainer(), 'globex', 'Globex');
        self::useTenant(self::getContainer(), $globex);
        self::createUser('carla@example.com');
        self::useTenant(self::getContainer(), $this->tenant('default'));
        $link = $this->invite('carla@example.com');
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', $link);
        $this->client->submitForm('Join', ['invitation[password]' => 'not her password at all']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'This is not the password of the account.');

        $this->client->submitForm('Join', ['invitation[password]' => self::PASSWORD]);
        self::assertResponseRedirects('/invitation/welcome', 303);
        self::assertContains('carla@example.com', $this->members());
    }

    /**
     * New invitations are no way to more guesses: the tries are counted per account.
     */
    public function testInvitingAgainGivesNoMoreTriesAtAnAccountsPassword(): void
    {
        self::signInAsSuperAdmin($this->client);
        $globex = self::createTenant(self::getContainer(), 'globex', 'Globex');
        self::useTenant(self::getContainer(), $globex);
        self::createUser('carla@example.com');
        self::useTenant(self::getContainer(), $this->tenant('default'));
        $first = $this->invite('carla@example.com');
        $second = $this->invite('carla@example.com');
        $this->client->request('GET', $first);
        self::assertResponseStatusCodeSame(404, 'Inviting again replaces the pending invitation.');

        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('GET', $second);
            $this->client->submitForm('Join', ['invitation[password]' => 'not her password at all']);
        }
        $this->client->request('GET', $second);
        self::assertResponseStatusCodeSame(404, 'Five wrong passwords burn an invitation.');

        $third = $this->invite('carla@example.com');
        $this->client->request('GET', $third);
        $this->client->submitForm('Join', ['invitation[password]' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(422, 'Even the right password waits once the account had its tries.');
        self::assertSelectorTextContains('main', 'Too many tries');
        self::assertNotContains('carla@example.com', $this->members());
    }

    /**
     * Inviting a member would only be a way to touch their roles: refused.
     */
    public function testAMemberIsNotInvited(): void
    {
        self::signInAsSuperAdmin($this->client);
        self::createUser('bruno@example.com');

        $this->client->request('GET', '/admin/members');
        $this->client->submitForm('Invite', ['add_member[email]' => 'Bruno@example.com']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.md-flash--error', 'bruno@example.com is a member already.');
        self::assertSelectorNotExists('input[readonly]');
    }

    /**
     * Become a member meanwhile (the operator added them), they keep what they have.
     */
    public function testAcceptingAsAMemberAlreadyChangesNothing(): void
    {
        $rbac = self::getContainer()->get(RbacManagerInterface::class);
        $rbac->addItem('ROLE_HR', AuthItemType::ROLE);
        self::signIn($this->client, 'boss@example.com', [Permissions::SUPER_ADMIN_ROLE]);
        $users = self::getContainer()->get(UserManagerInterface::class);
        $users->removeFromTenant('boss@example.com', $this->tenant('default')->id);
        $rbac->addChild('ROLE_HR', Permissions::MEMBERS);
        self::signIn($this->client, 'hr@example.com', ['ROLE_HR']);
        $link = $this->invite('boss@example.com');
        self::getContainer()->get(UserManagerInterface::class)->addToTenant('boss@example.com', $this->tenant('default')->id);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $link);
        $this->client->submitForm('Join', ['invitation[password]' => self::PASSWORD]);
        $this->client->followRedirect();
        $this->client->followRedirect();

        self::assertSame([Permissions::SUPER_ADMIN_ROLE], self::getContainer()->get(TenantRolesInterface::class)->assignmentsOf('boss@example.com'));
    }

    public function testARevokedExpiredOrOtherTenantsInvitationIsNotFound(): void
    {
        self::signInAsSuperAdmin($this->client);
        $revoked = $this->invite('dora@example.com');
        $crawler = $this->client->request('GET', '/admin/members');
        $this->client->submit($crawler->filter('form[name^="revoke_"]')->form());
        $expired = $this->invite('eva@example.com');
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE core.tenant_invitation SET expires_at = now() - INTERVAL '1 minute' WHERE email = 'eva@example.com'");
        $elsewhere = $this->invite('fede@example.com');
        self::createTenant(self::getContainer(), 'globex', 'Globex');
        $this->client->getCookieJar()->clear();

        foreach ([$revoked, $expired, str_replace('://localhost', '://globex.localhost', $elsewhere)] as $link) {
            $this->client->request('GET', $link);
            self::assertResponseStatusCodeSame(404, $link);
        }
    }

    /**
     * A member removed keeps their roles here. Invited back by an admin who doesn't hold them
     * (written in any case), they come back without them; the admin is told nothing.
     */
    public function testAMemberInvitedBackByALesserAdminComesBackWithoutTheirRoles(): void
    {
        $rbac = self::getContainer()->get(RbacManagerInterface::class);
        $rbac->addItem('ROLE_HR', AuthItemType::ROLE);
        self::signIn($this->client, 'mallory@example.com', [Permissions::SUPER_ADMIN_ROLE]);
        self::getContainer()->get(UserManagerInterface::class)->removeFromTenant('mallory@example.com', $this->tenant('default')->id);
        $rbac->addChild('ROLE_HR', Permissions::MEMBERS);
        self::signIn($this->client, 'hr@example.com', ['ROLE_HR']);

        $link = $this->invite(' Mallory@Example.COM ');
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $link);
        $this->client->submitForm('Join', ['invitation[password]' => self::PASSWORD]);

        self::assertResponseRedirects('/invitation/welcome', 303);
        $this->client->followRedirect();
        // The next request's container: the RBAC bundle keeps what it read during one in memory.
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.md-topbar__user', 'Signed in, and still so on the next request.');
        self::assertSelectorNotExists('.md-topbar__nav a[href="/admin"]', 'Without the super admin role.');
        self::assertContains('mallory@example.com', $this->members());
        self::assertSame([], self::getContainer()->get(TenantRolesInterface::class)->assignmentsOf('mallory@example.com'));
    }

    public function testGivesAndTakesTheTenantsRoles(): void
    {
        self::signInAsSuperAdmin($this->client);
        self::createUser('bruno@example.com');
        self::getContainer()->get(RbacManagerInterface::class)->addItem('ROLE_EDITOR', AuthItemType::ROLE);

        $this->setRoles('bruno@example.com', ['ROLE_EDITOR']);
        self::assertSame(['ROLE_EDITOR'], $this->rolesOf('bruno@example.com'));

        $this->setRoles('bruno@example.com', []);
        self::assertSame([], $this->rolesOf('bruno@example.com'));
    }

    public function testRemovesAMember(): void
    {
        self::signInAsSuperAdmin($this->client);
        self::createUser('bruno@example.com');

        $crawler = $this->client->request('GET', '/admin/members');
        $this->client->submit($crawler->filter(\sprintf('form[name="remove_%s"]', self::key('bruno@example.com')))->form());

        self::assertResponseRedirects('/admin/members', 303);
        self::assertSame(['admin@example.com'], $this->members());
    }

    /**
     * No lockout: your own roles and membership are another admin's to change.
     */
    public function testYourOwnRowCannotBeChanged(): void
    {
        self::signInAsSuperAdmin($this->client);

        $crawler = $this->client->request('GET', '/admin/members');
        self::assertCount(0, $crawler->filter(\sprintf('form[name="roles_%1$s"], form[name="remove_%1$s"]', self::key('admin@example.com'))));

        $this->client->request('POST', \sprintf('/admin/members/%s/remove', self::key('admin@example.com')));
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['admin@example.com'], $this->members());
    }

    /**
     * Managing members is no way to more power: you give, take and remove only what you hold.
     */
    public function testGrantsOnlyTheRolesTheAdminHolds(): void
    {
        $rbac = self::getContainer()->get(RbacManagerInterface::class);
        $rbac->addItem('ROLE_HR', AuthItemType::ROLE);
        $rbac->addItem('ROLE_EDITOR', AuthItemType::ROLE);
        self::signIn($this->client, 'boss@example.com', [Permissions::SUPER_ADMIN_ROLE]);
        $rbac->addChild('ROLE_HR', Permissions::MEMBERS);
        self::signIn($this->client, 'hr@example.com', ['ROLE_HR']);
        self::createUser('mallory@example.com');

        $crawler = $this->client->request('GET', '/admin/members');
        $options = $crawler->filter(\sprintf('form[name="roles_%s"] input[type="checkbox"]', self::key('mallory@example.com')))->each(static fn ($input): string => (string) $input->attr('value'));
        self::assertSame(['ROLE_HR'], $options, 'Only the roles HR holds are offered.');
        // The boss holds a role HR doesn't: their row can't be changed by HR.
        self::assertCount(0, $crawler->filter(\sprintf('form[name="remove_%s"]', self::key('boss@example.com'))));

        // A forged request is refused all the same.
        $token = $crawler->filter(\sprintf('form[name="roles_%s"] input[name$="[_token]"]', self::key('mallory@example.com')))->attr('value');
        $this->client->request('POST', \sprintf('/admin/members/%s/roles', self::key('mallory@example.com')), [
            'roles_'.self::key('mallory@example.com') => ['roles' => [Permissions::SUPER_ADMIN_ROLE], '_token' => $token],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->rolesOf('mallory@example.com'));

        $this->client->request('POST', \sprintf('/admin/members/%s/remove', self::key('boss@example.com')));
        self::assertResponseStatusCodeSame(403);
        self::assertContains('boss@example.com', $this->members());
    }

    /**
     * A permission assigned directly (rbac:assign) counts as a role does.
     */
    public function testLeavesAMemberHoldingAPermissionTheAdminDoesNotAlone(): void
    {
        $rbac = self::getContainer()->get(RbacManagerInterface::class);
        $rbac->addItem('ROLE_HR', AuthItemType::ROLE);
        self::signIn($this->client, 'designer@example.com', [Permissions::ADMINISTER]);
        $rbac->addChild('ROLE_HR', Permissions::MEMBERS);
        self::signIn($this->client, 'hr@example.com', ['ROLE_HR']);

        $crawler = $this->client->request('GET', '/admin/members');
        self::assertCount(0, $crawler->filter(\sprintf('form[name="remove_%s"]', self::key('designer@example.com'))));
        $this->client->request('POST', \sprintf('/admin/members/%s/remove', self::key('designer@example.com')));
        self::assertResponseStatusCodeSame(403);
        self::assertContains('designer@example.com', $this->members());
    }

    /**
     * Nor taking from a member above you what you could give to one below.
     */
    public function testLeavesTheRolesOfAMemberAboveTheAdminAlone(): void
    {
        $rbac = self::getContainer()->get(RbacManagerInterface::class);
        $rbac->addItem('ROLE_HR', AuthItemType::ROLE);
        self::signIn($this->client, 'boss@example.com', [Permissions::SUPER_ADMIN_ROLE, 'ROLE_HR']);
        $rbac->addChild('ROLE_HR', Permissions::MEMBERS);
        self::signIn($this->client, 'hr@example.com', ['ROLE_HR']);

        $crawler = $this->client->request('GET', '/admin/members');
        self::assertCount(0, $crawler->filter(\sprintf('form[name="roles_%s"]', self::key('boss@example.com'))));
        $token = $crawler->filter('form[name^="remove_"] input[name$="[_token]"], form[name^="roles_"] input[name$="[_token]"]')->count();
        self::assertSame(0, $token, 'No member below HR here: no form at all.');

        $this->client->request('POST', \sprintf('/admin/members/%s/roles', self::key('boss@example.com')), [
            'roles_'.self::key('boss@example.com') => ['roles' => []],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['ROLE_HR', 'ROLE_SUPER_ADMIN'], $this->rolesOf('boss@example.com'));
    }

    public function testNeedsThePermissionToManageMembers(): void
    {
        self::signIn($this->client, 'editor@example.com', [Permissions::ADMINISTER]);
        $this->client->request('GET', '/admin/members');
        self::assertResponseStatusCodeSame(403);
    }

    public function testThePermissionAloneOpensThePage(): void
    {
        self::signIn($this->client, 'hr@example.com', [Permissions::MEMBERS]);

        $this->client->request('GET', '/');
        self::assertSelectorExists('.md-topbar__nav a[href="/admin/members"]');
        self::assertSelectorNotExists('.md-topbar__nav a[href="/admin"]');

        $this->client->request('GET', '/admin/members');
        self::assertResponseIsSuccessful();
    }

    /**
     * Invites someone from the members page, as the signed-in admin.
     *
     * @return string the invitation's link
     */
    private function invite(string $email): string
    {
        $this->client->request('GET', '/admin/members');
        $this->client->submitForm('Invite', ['add_member[email]' => $email]);
        self::assertResponseRedirects('/admin/members', 303);
        $crawler = $this->client->followRedirect();

        return (string) $crawler->filter('input[readonly]')->attr('value');
    }

    /**
     * @param list<string> $roles
     */
    private function setRoles(string $email, array $roles): void
    {
        $crawler = $this->client->request('GET', '/admin/members');
        $name = 'roles_'.self::key($email);
        $form = $crawler->filter(\sprintf('form[name="%s"]', $name))->form();
        foreach ($form->all() as $field) {
            if ($field instanceof ChoiceFormField && 'checkbox' === $field->getType()) {
                \in_array($field->availableOptionValues()[0] ?? null, $roles, true) ? $field->tick() : $field->untick();
            }
        }
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/members', 303);
        $this->client->followRedirect();
    }

    /**
     * Read from the container of the last request, the page after the redirect: the RBAC
     * bundle keeps the assignments it read during a request in memory.
     *
     * @return list<string>
     */
    private function rolesOf(string $email): array
    {
        return self::getContainer()->get(TenantRolesInterface::class)->rolesOf($email);
    }

    /**
     * @return list<string>
     */
    private function members(): array
    {
        return self::getContainer()->get(UserManagerInterface::class)->membersOf($this->tenant('default')->id);
    }

    private function tenant(string $slug): Tenant
    {
        $tenant = self::getContainer()->get(TenantRegistry::class)->bySlug($slug);
        self::assertInstanceOf(Tenant::class, $tenant);

        return $tenant;
    }

    private static function key(string $email): string
    {
        return substr(hash('sha256', $email), 0, 12);
    }
}
