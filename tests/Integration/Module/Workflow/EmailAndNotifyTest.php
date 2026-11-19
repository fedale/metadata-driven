<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Workflow;

use App\Module\Access\Application\PermissionSynchronizer;
use App\Module\Access\Contract\Permissions;
use App\Module\Metadata\Application\Command\CreateFieldCommand;
use App\Module\Metadata\Application\Handler\CreateFieldHandler;
use App\Module\Notification\Application\NotificationStore;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Definition\DefinitionValidator;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Application\WorkflowTester;
use App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition;
use App\Shared\Workspace\CurrentWorkspace;
use App\Tests\Support\CreatesRelatedObjects;
use Doctrine\DBAL\Connection;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Fedale\RbacBundle\Enum\AuthItemType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * send_email and notify: who they reach (a role's members, a member, a record's email field),
 * what leaves once the run's writes are kept, the plan's emails.
 */
final class EmailAndNotifyTest extends KernelTestCase
{
    use CreatesRelatedObjects;

    private const string PASSWORD = 'correct horse battery staple';

    private const string WELCOME = <<<'YAML'
        trigger: { on: created, object: person, when: after }
        steps:
          - key: tell
            type: send_email
            to:
              - { role: ROLE_CLERK }
              - { member: '{{ $user.email }}' }
              - { member: stranger@example.com }
              - { field: '{{ $trigger.record.email }}' }
            subject: 'Welcome, {{ $trigger.record.full_name }}'
            body: |
              {{ $trigger.record.full_name }} is in.
              City: {{ $trigger.record.city }}
        YAML;

    protected function setUp(): void
    {
        $container = self::getContainer();
        self::createRelatedObjects($container);
        $container->get(CreateFieldHandler::class)(new CreateFieldCommand('person', 'email', 'email', 'Email'));
        $container->get(PermissionSynchronizer::class)->syncAll();
        $users = $container->get(UserManagerInterface::class);
        foreach (['owner@example.com', 'clara@example.com', 'carlo@example.com', 'manager@example.com'] as $email) {
            $users->createUser($email, self::PASSWORD);
        }
        $rbac = $container->get(RbacManagerInterface::class);
        $rbac->assign('owner@example.com', Permissions::SUPER_ADMIN_ROLE);
        $rbac->addItem('ROLE_CLERK', AuthItemType::ROLE);
        $rbac->addItem('ROLE_MANAGER', AuthItemType::ROLE);
        $rbac->addChild('ROLE_MANAGER', 'ROLE_CLERK');
        $rbac->assign('clara@example.com', 'ROLE_CLERK');
        $rbac->assign('carlo@example.com', 'ROLE_CLERK');
        // Holds the role through another, as a member; one holding it is no member any more.
        $rbac->assign('manager@example.com', 'ROLE_MANAGER');
        $rbac->assign('former@example.com', 'ROLE_CLERK');
        $user = $container->get(UserProvider::class)->loadUserByIdentifier('owner@example.com');
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    public function testSendsToTheRolesMembersTheMembersAndTheRecordsAddress(): void
    {
        $this->activate(self::WELCOME);

        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada <b>Lovelace</b>', 'city' => 'Pisa', 'email' => 'ada@lovelace.example']));

        self::assertSame('completed', $this->lastRun('status'), json_encode($this->lastRun('error')) ?: '');
        $sent = array_map(static fn (Email $email): string => $email->getTo()[0]->getAddress(), array_values(array_filter(self::getMailerMessages(), static fn (object $message): bool => $message instanceof Email)));
        sort($sent);
        self::assertSame(['ada@lovelace.example', 'carlo@example.com', 'clara@example.com', 'manager@example.com', 'owner@example.com'], $sent);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Welcome, Ada <b>Lovelace</b>', $email->getSubject());
        // A record's value is text in the email: escaped, never markup.
        self::assertEmailHtmlBodyContains($email, 'Ada &lt;b&gt;Lovelace&lt;/b&gt; is in.');
        self::assertEmailTextBodyContains($email, 'City: Pisa');
        // The log: how many, never who.
        $output = self::getContainer()->get(Connection::class)->fetchOne("SELECT output FROM core.workflow_run_step WHERE key = 'tell'");
        self::assertIsString($output);
        $output = json_decode($output, true);
        self::assertEquals(['recipients' => 5, 'left_out' => 1, 'sent' => true], $output);
        self::assertEquals(5, self::getContainer()->get(Connection::class)->fetchOne('SELECT emails FROM core.workflow_usage'));
    }

    public function testARunThatFailsSendsNothing(): void
    {
        $this->activate(self::WELCOME."\n  - { key: stop, type: refuse, message: 'No.' }\n");

        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada', 'email' => 'ada@lovelace.example']));

        self::assertSame('failed', $this->lastRun('status'));
        self::assertEmailCount(0);
    }

    public function testPastThePlansEmailsTheStepFails(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("UPDATE core.workspace SET plan = 'starter' WHERE id = ?", [self::getContainer()->get(CurrentWorkspace::class)->id()]);
        $connection->executeStatement('INSERT INTO core.workflow_usage (day, steps, emails) VALUES (CURRENT_DATE, 0, 998)');
        $this->activate(self::WELCOME);

        self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada']));

        self::assertSame('failed', $this->lastRun('status'));
        self::assertSame('The plan of this workspace allows 2 more emails this month: this step sends 4.', $this->lastRun('error'));
        self::assertEmailCount(0);
    }

    public function testNotifiesTheMembersBellsAndTheEmailOfThoseWhoChoseIt(): void
    {
        self::getContainer()->get(UserManagerInterface::class)->chooseNotificationsByEmail('clara@example.com', true);
        $this->activate(<<<'YAML'
            trigger: { on: created, object: person, when: after }
            steps:
              - key: ping
                type: notify
                to: [{ role: ROLE_CLERK }, { member: stranger@example.com }]
                title: 'New person: {{ $trigger.record.full_name }}'
                text: 'From {{ $trigger.record.city }}'
                link: { object: person, id: '{{ $trigger.record.id }}' }
            YAML);

        $ada = self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada', 'city' => 'Pisa']));

        self::assertSame('completed', $this->lastRun('status'), json_encode($this->lastRun('error')) ?: '');
        $store = self::getContainer()->get(NotificationStore::class);
        foreach (['clara@example.com', 'carlo@example.com', 'manager@example.com'] as $member) {
            self::assertSame(1, $store->unreadCount($member), $member);
        }
        $notification = $store->latest('carlo@example.com', 1)[0];
        self::assertSame(['New person: Ada', 'From Pisa', '/o/person/'.$ada->id.'/edit', 'Workflow Notify'], [$notification->title, $notification->text, $notification->link, $notification->origin]);
        self::assertEmailCount(1);
        self::assertEquals(1, self::getContainer()->get(Connection::class)->fetchOne('SELECT emails FROM core.workflow_usage'));
    }

    public function testATestOnARecordShowsWhatWouldGoAndSendsNothing(): void
    {
        $ada = self::getContainer()->get(RecordManagerInterface::class)->create(new RecordInput('person', ['full_name' => 'Ada', 'email' => 'ada@lovelace.example']));

        $run = self::getContainer()->get(WorkflowTester::class)->test('Welcome', self::WELCOME, $ada->id);

        self::assertSame('completed', $run->status, (string) $run->error);
        // The role's three members, whoever runs the test, the record's address.
        self::assertSame(['recipients' => 5, 'left_out' => 1, 'sent' => false], $run->steps[0]['output']);
        self::assertEmailCount(0);
    }

    public function testOnlyAnEmailFieldGivesAnAddress(): void
    {
        $validator = self::getContainer()->get(DefinitionValidator::class);
        $parser = new DefinitionParser();
        try {
            $validator->validate($parser->parse("trigger: { on: created, object: person, when: after }\nsteps: [{ key: tell, type: send_email, to: [{ field: '{{ \$trigger.record.city }}' }, { role: ROLE_NOBODY }], subject: Hi, body: Hi }]"));
            self::fail('Refused.');
        } catch (InvalidWorkflowDefinition $e) {
            self::assertStringContainsString('steps.0.to.0.field: An email field of a record', $e->getMessage());
            self::assertStringContainsString('steps.0.to.1.role: "ROLE_NOBODY" is no role of this workspace.', $e->getMessage());
        }
        // An address as written is no recipient.
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessage('steps.0.to.0.field: One {{ path }} to an email field of a record');
        $parser->parse("trigger: { on: created, object: person, when: after }\nsteps: [{ key: tell, type: send_email, to: [{ field: ada@lovelace.example }], subject: Hi, body: Hi }]");
    }

    private function activate(string $yaml): void
    {
        $manager = self::getContainer()->get(WorkflowManager::class);
        $manager->activate($manager->create(str_contains($yaml, 'notify') ? 'Notify' : 'Welcome', null, $yaml));
    }

    private function lastRun(string $column): mixed
    {
        return self::getContainer()->get(Connection::class)->fetchOne(\sprintf('SELECT %s FROM core.workflow_run ORDER BY started_at DESC LIMIT 1', $column));
    }
}
