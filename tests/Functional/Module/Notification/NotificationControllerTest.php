<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\Notification;

use App\Module\Notification\Application\NotificationStore;
use App\Module\Notification\Contract\Dto\NotificationMessage;
use App\Module\Notification\Contract\NotifierInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The bell, its menu and the history: each user's own notifications.
 */
final class NotificationControllerTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::signIn($this->client, 'anna@example.com');
        self::createUser('bruno@example.com');
    }

    public function testTheBellCountsTheUnread(): void
    {
        $this->client->request('GET', '/');
        self::assertSelectorExists('.md-bell__count[hidden]');

        $this->notify(['anna@example.com'], 'Invoice 42 is overdue', '/o/invoice/42');
        $this->notify(['anna@example.com', 'bruno@example.com'], 'Invoice 43 is overdue');

        $this->client->request('GET', '/');
        self::assertSelectorTextSame('.md-bell__count', '2');
        $this->client->request('GET', '/notifications/count');
        self::assertResponseIsSuccessful();
        self::assertSame(['unread' => 2], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testTheMenuListsTheLatestAndOpeningOneReadsItAndLeadsToItsPage(): void
    {
        $this->notify(['anna@example.com'], 'Invoice 42 is overdue', '/o/invoice/42');
        $this->notify(['anna@example.com'], 'Invoice 43 is overdue');

        $crawler = $this->client->request('GET', '/notifications/menu');
        self::assertSelectorExists('turbo-frame#notification-menu');
        self::assertSelectorCount(2, '.md-notification--unread');
        self::assertSelectorTextContains('.md-notification:first-child', 'Invoice 43');

        $this->client->click($crawler->filter('.md-notification a')->eq(1)->link());
        self::assertResponseRedirects('/o/invoice/42', 303);
        $this->client->request('GET', '/notifications/menu');
        self::assertSelectorCount(1, '.md-notification--unread');

        // One without a link leads to the history.
        $crawler = $this->client->request('GET', '/notifications/menu');
        $this->client->click($crawler->filter('.md-notification a')->eq(0)->link());
        self::assertResponseRedirects('/notifications', 303);
    }

    public function testNoOneOpensAnothersNotification(): void
    {
        $this->notify(['bruno@example.com'], 'For Bruno');
        $bruno = self::getContainer()->get(NotificationStore::class)->latest('bruno@example.com', 1)[0];

        $this->client->request('GET', '/notifications/menu');
        self::assertSelectorTextNotContains('main, body', 'For Bruno');
        $this->client->request('GET', '/notifications/'.$bruno->id.'/open');
        self::assertResponseStatusCodeSame(404);
        self::assertFalse(self::getContainer()->get(NotificationStore::class)->latest('bruno@example.com', 1)[0]->isRead());
    }

    public function testTheHistoryMarksAllAsRead(): void
    {
        $this->notify(['anna@example.com'], 'One');
        $this->notify(['anna@example.com'], 'Two');
        $this->notify(['bruno@example.com'], 'For Bruno');

        $this->client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, 'main .md-notification');
        $this->client->submitForm('Mark all as read');
        self::assertResponseRedirects('/notifications', 303);
        $this->client->followRedirect();

        self::assertSelectorCount(0, 'main .md-notification--unread');
        self::assertSelectorNotExists('main form[action="/notifications/read-all"]');
        self::assertSame(1, self::getContainer()->get(NotificationStore::class)->unreadCount('bruno@example.com'));
    }

    public function testTheHistoryIsPaged(): void
    {
        for ($i = 1; $i <= 30; ++$i) {
            $this->notify(['anna@example.com'], 'Notification '.$i);
        }

        $this->client->request('GET', '/notifications');
        self::assertSelectorCount(25, 'main .md-notification');
        self::assertSelectorTextContains('.md-pager', 'Page 1 of 2');
        $this->client->clickLink('Older →');
        self::assertSelectorCount(5, 'main .md-notification');
    }

    public function testTheAccountPageChoosesEmail(): void
    {
        $crawler = $this->client->request('GET', '/account');
        $form = $crawler->filter('form[name="notifications"]')->form();
        $checkbox = $form['notifications[by_email]'];
        self::assertInstanceOf(ChoiceFormField::class, $checkbox);
        $checkbox->tick();
        $this->client->submit($form);
        self::assertResponseRedirects('/account', 303);

        self::assertTrue(self::getContainer()->get(UserManagerInterface::class)->profile('anna@example.com')->notifyByEmail);
    }

    /**
     * @param list<string> $emails
     */
    private function notify(array $emails, string $title, ?string $link = null): void
    {
        self::getContainer()->get(NotifierInterface::class)->notify($emails, new NotificationMessage($title, null, $link));
    }
}
