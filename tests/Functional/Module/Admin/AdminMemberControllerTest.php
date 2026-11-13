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
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Fedale\RbacBundle\Enum\AuthItemType;
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

    public function testAddsAnExistingAccountOrCreatesOne(): void
    {
        self::signInAsSuperAdmin($this->client);
        $globex = self::createTenant(self::getContainer(), 'globex', 'Globex');
        self::useTenant(self::getContainer(), $globex);
        self::createUser('carla@example.com');
        self::useTenant(self::getContainer(), $this->tenant('default'));

        $this->add(['email' => 'carla@example.com']);
        self::assertSelectorTextContains('.md-flash--success', 'carla@example.com may now sign in here.');

        // A new email needs a password.
        $this->client->request('GET', '/admin/members');
        $this->client->submitForm('Add', ['add_member[email]' => 'dora@example.com']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'give it an initial password');

        $this->add(['email' => 'dora@example.com', 'first_name' => 'Dora', 'last_name' => 'Maar', 'password' => self::PASSWORD]);
        self::assertSelectorTextContains('.md-flash--success', 'Account dora@example.com created');

        self::assertSame(['admin@example.com', 'carla@example.com', 'dora@example.com'], $this->members());
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
     * @param array<string, string> $fields
     */
    private function add(array $fields): void
    {
        $this->client->request('GET', '/admin/members');
        $values = [];
        foreach ($fields as $name => $value) {
            $values[\sprintf('add_member[%s]', $name)] = $value;
        }
        $this->client->submitForm('Add', $values);
        self::assertResponseRedirects('/admin/members', 303);
        $this->client->followRedirect();
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
