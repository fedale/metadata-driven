<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Workflow;

use App\Module\Access\Contract\Permissions;
use App\Module\Access\Contract\WorkspaceRolesInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Dto\RecordOutput;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Domain\Model\Workflow;
use App\Tests\Support\CreatesRelatedObjects;
use App\Tests\Support\SignsIn;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * A manual workflow on a record's page: offered to whoever may run it, run at once or after its
 * form, its result a flash message.
 */
final class ManualRunControllerTest extends WebTestCase
{
    use CreatesRelatedObjects;
    use SignsIn;

    private KernelBrowser $client;

    private RecordOutput $ada;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::createRelatedObjects(self::getContainer());
        self::signInAsSuperAdmin($this->client);
        $this->ada = self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada']));
    }

    public function testOnlyWhoHoldsItsPermissionIsOfferedItOrMayRunIt(): void
    {
        $workflow = $this->activate('Pet', "trigger: { on: manual, object: person, label: Give a pet }\nsteps: [{ key: pet, type: create_record, object: pet, values: { name: Rex }, act_as: ROLE_SUPER_ADMIN }]");
        self::signIn($this->client, 'anna@example.com', ['record.person.read']);

        $this->client->request('GET', '/o/person/'.$this->ada->id.'/edit');
        self::assertSelectorNotExists('.md-menu');
        $this->client->request('POST', $this->url($workflow), ['workflow_run' => []]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->pets());

        // Holding it, on a record of an object it does not run on: no such page.
        self::getContainer()->get(RbacManagerInterface::class)->assign('anna@example.com', Permissions::workflowRun($workflow->getId()->toRfc4122()));
        $this->client->request('GET', '/w/'.$workflow->getId()->toRfc4122().'/run/'.$this->client->getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('pet', ['name' => 'Tom']))->id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAWorkflowWithoutAFormRunsFromTheRecordsPage(): void
    {
        $this->activate('Pet', "trigger: { on: manual, object: person, label: Give a pet }\nsteps: [{ key: pet, type: create_record, object: pet, values: { name: 'Pet of {{ \$trigger.record.full_name }}' } }]");
        $this->holdEveryWorkflow();

        $crawler = $this->client->request('GET', '/o/person/'.$this->ada->id.'/edit');
        self::assertSelectorTextContains('.md-menu', 'Give a pet');
        $this->client->submit($crawler->filter('form[id^="md-action-"]')->form());
        self::assertResponseRedirects('/o/person/'.$this->ada->id.'/edit', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', '"Give a pet" done.');
        self::assertSame(['Pet of Ada'], $this->pets());
    }

    public function testAWorkflowWithAFormAsksItsAnswersFirst(): void
    {
        $workflow = $this->activate('Pet', <<<'YAML'
            trigger:
              on: manual
              object: person
              label: Name a pet
              form: [{ name: name, label: Pet name, type: text, required: true }, { name: kind, label: Kind, type: select, options: [cat, dog] }]
            steps:
              - { key: pet, type: create_record, object: pet, values: { name: '{{ $trigger.input.name }} the {{ $trigger.input.kind }}' } }
              - { key: not_cat, type: filter, when: { field: $trigger.input.kind, op: neq, value: cat } }
              - { key: no, type: refuse, message: 'Cats only.' }
            YAML);
        $this->holdEveryWorkflow();

        $this->client->request('GET', '/o/person/'.$this->ada->id.'/edit');
        $this->client->clickLink('Name a pet');
        self::assertSelectorTextContains('h1', 'Name a pet');
        $this->client->submitForm('Run', ['workflow_run[name]' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->pets());

        // A refuse keeps nothing: its message is shown.
        $this->client->submitForm('Run', ['workflow_run[name]' => 'Rex', 'workflow_run[kind]' => 'dog']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--error', '"Name a pet" failed, nothing was kept: Cats only.');
        self::assertSame([], $this->pets());

        $this->client->request('GET', $this->url($workflow));
        $this->client->submitForm('Run', ['workflow_run[name]' => 'Tom', 'workflow_run[kind]' => 'cat']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', '"Name a pet" done.');
        self::assertSame(['Tom the cat'], $this->pets());
    }

    public function testAGridsSelectionRunsItFromTheBulkBar(): void
    {
        $workflow = $this->activate('Pets', <<<'YAML'
            trigger: { on: manual, object: person, label: Give pets, selection: each, form: [{ name: name, label: Pet name, type: text, required: true }] }
            steps:
              - { key: pet, type: create_record, object: pet, values: { name: '{{ $trigger.input.name }} of {{ $trigger.record.full_name }}' } }
            YAML);
        $bob = self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Bob']));
        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Carla']));

        // Without its permission: no selection, no bulk bar.
        self::signIn($this->client, 'bea@example.com', ['record.person.read']);
        $this->client->request('GET', '/o/person');
        self::assertSelectorNotExists('.gv-bulk-bar');
        // Its permission without reading the records: not even how many are selected.
        self::signIn($this->client, 'carl@example.com', [Permissions::workflowRun($workflow->getId()->toRfc4122())]);
        $this->client->request('GET', '/w/'.$workflow->getId()->toRfc4122().'/run-selection?all=1');
        self::assertResponseStatusCodeSame(403);
        $this->holdEveryWorkflow();
        $this->client->request('GET', '/o/person');
        self::assertSelectorTextContains('.gv-bulk-bar', 'Give pets');
        self::assertSelectorExists('input[data-gridview-selection-target="checkbox"][value="'.$bob->id.'"]');

        // The modal fetches the form, the selection in its url.
        $url = '/w/'.$workflow->getId()->toRfc4122().'/run-selection?ids%5B%5D='.$this->ada->id.'&ids%5B%5D='.$bob->id;
        $this->client->xmlHttpRequest('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('.md-run-selection', 'Runs on each of the 2');

        $this->client->request('GET', $url);
        $this->client->submitForm('Run', ['workflow_run[name]' => 'Rex']);
        self::assertResponseRedirects('/o/person', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', '"Give pets" done on 2 record(s).');
        self::assertSame(['Rex of Ada', 'Rex of Bob'], $this->sortedPets());

        // Every record of the grid: Ada, Bob and Carla.
        $this->client->request('GET', '/w/'.$workflow->getId()->toRfc4122().'/run-selection?all=1');
        self::assertSelectorTextContains('.md-run-selection', 'Runs on each of the 3');
    }

    public function testASelectionOfRunsThatWaitIsNoFailure(): void
    {
        $workflow = $this->activate('Later', "trigger: { on: manual, object: person, label: Later, selection: each }\nsteps: [{ key: later, type: wait, for: 2 days }, { key: seen, type: filter, when: { field: \$trigger.record.id, op: not_empty } }]");
        $bob = self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Bob']));
        $this->holdEveryWorkflow();

        $this->client->request('GET', '/w/'.$workflow->getId()->toRfc4122().'/run-selection?ids%5B%5D='.$this->ada->id.'&ids%5B%5D='.$bob->id);
        $this->client->submitForm('Run');
        self::assertResponseRedirects('/o/person', 303);
        $this->client->followRedirect();

        // Stopped at their wait, to go on in two days: said so, and no error.
        self::assertSelectorTextContains('.md-flash--success', '"Later" goes on for 2 record(s): it waits until');
        self::assertSelectorNotExists('.md-flash--error');
    }

    public function testItsPermissionIsGivenOnTheRolesPage(): void
    {
        $this->activate('Pet', "trigger: { on: manual, object: person, label: Give a pet }\nsteps: [{ key: pet, type: filter, when: { field: \$trigger.record.city, op: empty } }]");

        $this->client->request('GET', '/admin/roles');
        self::assertSelectorTextContains('body', 'Run "Pet"');
    }

    private function activate(string $name, string $yaml): Workflow
    {
        // As the super admin, outside a request.
        $user = self::getContainer()->get(UserProvider::class)->loadUserByIdentifier('admin@example.com');
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $manager = self::getContainer()->get(WorkflowManager::class);
        $workflow = $manager->create($name, null, $yaml);
        $manager->activate($workflow);

        return $workflow;
    }

    /**
     * The workflows' permissions, given to a user who is no super admin: the menu shows them.
     */
    private function holdEveryWorkflow(): void
    {
        $permissions = array_filter(array_keys(self::getContainer()->get(WorkspaceRolesInterface::class)->permissions()), static fn (string $permission): bool => null !== Permissions::workflowOf($permission));
        self::signIn($this->client, 'anna@example.com', ['record.person.read', 'record.pet.create', ...$permissions]);
    }

    private function url(Workflow $workflow): string
    {
        return '/w/'.$workflow->getId()->toRfc4122().'/run/'.$this->ada->id;
    }

    /**
     * @return list<mixed>
     *
     * @phpstan-impure the records change between two calls
     */
    private function sortedPets(): array
    {
        $pets = $this->pets();
        sort($pets);

        return $pets;
    }

    /**
     * @return list<mixed>
     *
     * @phpstan-impure the records change between two calls
     */
    private function pets(): array
    {
        return array_map(static fn (RecordOutput $record): mixed => $record->get('name'), iterator_to_array(self::getContainer()->get(RecordFinderInterface::class)->each('pet'), false));
    }
}
