<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\User;

use App\Module\User\Contract\UserManagerInterface;
use App\Tests\Support\SignsIn;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Mime\Email;

/**
 * "Forgot your password?": a link by email, the same answer for every address, used once.
 */
final class PasswordResetControllerTest extends WebTestCase
{
    use SignsIn;

    private const string NEW_PASSWORD = 'a much longer passphrase now';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->clearLimits();
    }

    protected function tearDown(): void
    {
        $this->clearLimits();
        parent::tearDown();
    }

    public function testTheLoginPageLinksToIt(): void
    {
        $this->client->request('GET', '/login');
        $this->client->clickLink('Forgot your password?');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Reset your password');
    }

    public function testResetsThePasswordByALinkThatWorksOnceAndEndsTheSessions(): void
    {
        self::signIn($this->client, 'anna@example.com');
        $this->client->request('GET', '/');
        $session = $this->client->getCookieJar()->get('MOCKSESSID');
        self::assertInstanceOf(Cookie::class, $session);
        $this->client->getCookieJar()->clear();

        $link = $this->ask('Anna@Example.com');
        $path = (string) parse_url($link, \PHP_URL_PATH);
        self::assertMatchesRegularExpression('~^/password/reset/[A-Za-z0-9_-]{43}$~', $path);

        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertSelectorTextContains('main', 'anna@example.com');
        $this->client->submitForm('Save the password', ['password_reset[password][first]' => 'short', 'password_reset[password][second]' => 'short']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', '12 characters');
        $this->client->submitForm('Save the password', ['password_reset[password][first]' => self::NEW_PASSWORD, 'password_reset[password][second]' => 'something else entirely']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'The two passwords differ.');
        $this->client->submitForm('Save the password', ['password_reset[password][first]' => self::NEW_PASSWORD, 'password_reset[password][second]' => self::NEW_PASSWORD]);
        self::assertResponseRedirects('/login', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--success', 'Your password is changed');

        // Once.
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);

        // The session of before ended.
        $this->client->getCookieJar()->set($session);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login', 302);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => 'anna@example.com', 'password' => self::NEW_PASSWORD]);
        self::assertResponseRedirects('/', 302);
    }

    public function testTheAnswerIsTheSameForAnAddressWithoutAnAccount(): void
    {
        self::createUser('anna@example.com');

        $this->client->request('GET', '/password/forgot');
        $this->client->submitForm('Send the link', ['password_forgot[email]' => 'nobody@example.com']);
        self::assertEmailCount(0);
        self::assertResponseRedirects('/login', 303);
        $nobody = $this->client->followRedirect()->filter('.md-flash--success')->text();

        $this->ask('anna@example.com');
        $anna = $this->client->getCrawler()->filter('.md-flash--success')->text();

        self::assertSame($nobody, $anna);
    }

    public function testTheEmailIsInTheAccountsLanguage(): void
    {
        self::createUser('anna@example.com');
        self::getContainer()->get(UserManagerInterface::class)->changeLocale('anna@example.com', 'it');

        $this->client->request('GET', '/password/forgot');
        $this->client->submitForm('Send the link', ['password_forgot[email]' => 'anna@example.com']);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Scegli una nuova password', $email->getSubject());
        self::assertEmailTextBodyContains($email, 'Il link vale una volta');
    }

    public function testANewLinkReplacesTheOldOneAndAnExpiredOneIsNotFound(): void
    {
        self::createUser('anna@example.com');
        $first = (string) parse_url($this->ask('anna@example.com'), \PHP_URL_PATH);
        $second = (string) parse_url($this->ask('anna@example.com'), \PHP_URL_PATH);

        $this->client->request('GET', $first);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $second);
        self::assertResponseIsSuccessful();

        self::getContainer()->get(Connection::class)->executeStatement("UPDATE core.password_reset SET expires_at = now() - INTERVAL '1 minute'");
        $this->client->request('GET', $second);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnInactiveAccountGetsNoLink(): void
    {
        self::createUser('anna@example.com');
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE core.app_user SET is_active = false WHERE email = 'anna@example.com'");

        $this->client->request('GET', '/password/forgot');
        $this->client->submitForm('Send the link', ['password_forgot[email]' => 'anna@example.com']);

        self::assertEmailCount(0);
        self::assertResponseRedirects('/login', 303);
    }

    public function testTheRequestsAreLimitedPerAddress(): void
    {
        self::createUser('anna@example.com');
        for ($i = 0; $i < 3; ++$i) {
            $this->ask('anna@example.com');
        }

        $this->client->request('GET', '/password/forgot');
        $this->client->submitForm('Send the link', ['password_forgot[email]' => 'anna@example.com']);
        self::assertEmailCount(0);
        self::assertResponseRedirects('/password/forgot', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.md-flash--error', 'Too many requests');
    }

    public function testTheRequestsAreLimitedPerIp(): void
    {
        foreach (['a', 'b', 'c'] as $who) {
            $this->client->request('GET', '/password/forgot');
            $this->client->submitForm('Send the link', ['password_forgot[email]' => $who.'@example.com']);
            self::assertResponseRedirects('/login', 303);
        }

        $this->client->request('GET', '/password/forgot');
        $this->client->submitForm('Send the link', ['password_forgot[email]' => 'd@example.com']);
        self::assertResponseRedirects('/password/forgot', 303);
    }

    public function testTheConsoleOffersItToo(): void
    {
        self::createUser('anna@example.com');
        $this->client->request('GET', 'http://console.localhost/password/forgot');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Send the link', ['password_forgot[email]' => 'anna@example.com']);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertEmailTextBodyContains($message, 'http://console.localhost/password/reset/');
    }

    /**
     * Any host reaches the default workspace: the link names the configured one, never the
     * request's, or a forged Host would mail a victim a link handing the token to another site.
     */
    public function testTheLinkNamesTheConfiguredHostNotTheRequests(): void
    {
        self::createUser('anna@example.com');
        $this->client->request('GET', 'http://attacker.example/password/forgot');
        $this->client->submitForm('Send the link', ['password_forgot[email]' => 'anna@example.com']);

        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertEmailTextBodyNotContains($message, 'attacker.example');
        self::assertEmailTextBodyContains($message, 'http://localhost/password/reset/');
    }

    /**
     * Asks for a link, signed out, and returns the one the email carries.
     */
    private function ask(string $email): string
    {
        $this->client->request('GET', '/password/forgot');
        $this->client->submitForm('Send the link', ['password_forgot[email]' => $email]);
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertResponseRedirects('/login', 303);
        $this->client->followRedirect();
        if (1 !== preg_match('~https?://\S+/password/reset/[A-Za-z0-9_-]{43}~', (string) $message->getTextBody(), $match)) {
            self::fail('The email carries no link.');
        }

        return $match[0];
    }

    private function clearLimits(): void
    {
        $limits = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $limits);
        $limits->clear();
    }
}
