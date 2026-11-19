<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Notification;

use App\Module\Notification\Application\NotificationStore;
use App\Module\Notification\Contract\Dto\NotificationMessage;
use App\Module\Notification\Contract\NotifierInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Shared\Infrastructure\Workspace\WorkspaceRegistry;
use App\Shared\Workspace\WorkspaceUrls;
use App\Tests\Support\CreatesWorkspaces;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * Notifying the members of the workspace: the bell of each, an email for those who chose it.
 */
final class NotifierTest extends KernelTestCase
{
    use CreatesWorkspaces;

    private const string PASSWORD = 'correct horse battery staple';

    protected function setUp(): void
    {
        $users = self::getContainer()->get(UserManagerInterface::class);
        // Members of the default workspace, the tests' own.
        $users->createUser('anna@example.com', self::PASSWORD, 'Anna', 'Magnani');
        $users->createUser('bruno@example.com', self::PASSWORD, 'Bruno', 'Ganz');
        $users->createUser('carla@example.com', self::PASSWORD, 'Carla', 'Bruni');
    }

    public function testNotifiesTheMembersOnlyOnceEach(): void
    {
        $container = self::getContainer();
        // Dora is a member of another workspace only; Carla's account is disabled.
        $default = $container->get(WorkspaceRegistry::class)->bySlug('default');
        self::assertNotNull($default);
        self::useWorkspace($container, self::createWorkspace($container, 'globex', 'Globex'));
        $container->get(UserManagerInterface::class)->createUser('dora@example.com', self::PASSWORD);
        self::useWorkspace($container, $default);
        $container->get(Connection::class)->executeStatement("UPDATE core.app_user SET is_active = false WHERE email = 'carla@example.com'");

        $notified = $container->get(NotifierInterface::class)->notify(
            ['anna@example.com', 'ANNA@example.com ', 'bruno@example.com', 'carla@example.com', 'dora@example.com', 'nobody@example.com'],
            new NotificationMessage('Invoice 42 is overdue', 'Due on 2026-11-01', '/o/invoice/42', 'Workflow Reminders'),
        );

        self::assertSame(2, $notified->notified);
        self::assertSame(3, $notified->leftOut);
        $store = $container->get(NotificationStore::class);
        self::assertSame(1, $store->unreadCount('anna@example.com'));
        self::assertSame(1, $store->unreadCount('bruno@example.com'));
        self::assertSame(0, $store->unreadCount('carla@example.com'));
        $anna = $store->latest('anna@example.com', 10)[0];
        self::assertSame(['Invoice 42 is overdue', 'Due on 2026-11-01', '/o/invoice/42', 'Workflow Reminders'], [$anna->title, $anna->text, $anna->link, $anna->origin]);
        // No one chose email.
        self::assertEmailCount(0);
    }

    public function testAMemberWhoChoseItGetsAnEmailInTheirLanguage(): void
    {
        $users = self::getContainer()->get(UserManagerInterface::class);
        $users->chooseNotificationsByEmail('anna@example.com', true);
        $users->changeLocale('anna@example.com', 'it');

        self::getContainer()->get(NotifierInterface::class)->notify(['anna@example.com', 'bruno@example.com'], new NotificationMessage('Fattura 42 scaduta', 'Scadeva il 1° novembre', '/o/invoice/42'));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('anna@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('Fattura 42 scaduta', $email->getSubject());
        // On the workspace's host, with the scheme and port of DEFAULT_URI (no request here).
        $urls = self::getContainer()->get(WorkspaceUrls::class);
        self::assertEmailHtmlBodyContains($email, $urls->ofCurrent('/o/invoice/42'));
        self::assertEmailHtmlBodyContains($email, $urls->ofCurrent('/account'));
        self::assertEmailTextBodyContains($email, 'Ricevi le notifiche anche per email');
        // The bell has it too.
        self::assertSame(1, self::getContainer()->get(NotificationStore::class)->unreadCount('anna@example.com'));
    }

    public function testEachWorkspaceKeepsItsOwn(): void
    {
        $container = self::getContainer();
        $container->get(NotifierInterface::class)->notify(['anna@example.com'], new NotificationMessage('In default'));
        $default = $container->get(WorkspaceRegistry::class)->bySlug('default');
        self::assertNotNull($default);

        self::useWorkspace($container, self::createWorkspace($container, 'globex', 'Globex'));
        $container->get(UserManagerInterface::class)->addToWorkspace('anna@example.com', (string) $container->get(WorkspaceRegistry::class)->bySlug('globex')?->id);

        self::assertSame(0, $container->get(NotificationStore::class)->unreadCount('anna@example.com'));
        self::useWorkspace($container, $default);
        self::assertSame(1, $container->get(NotificationStore::class)->unreadCount('anna@example.com'));
    }

    public function testTheOldOnesAreCollected(): void
    {
        $container = self::getContainer();
        $container->get(NotifierInterface::class)->notify(['anna@example.com', 'bruno@example.com'], new NotificationMessage('Old'));
        $container->get(Connection::class)->executeStatement("UPDATE core.notification SET created_at = now() - INTERVAL '91 days' WHERE recipient = 'anna@example.com'");

        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('notifications:gc'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('1 notification(s) older than 90 days deleted.', $tester->getDisplay());

        $store = $container->get(NotificationStore::class);
        self::assertSame(0, $store->count('anna@example.com'));
        self::assertSame(1, $store->count('bruno@example.com'));
    }
}
