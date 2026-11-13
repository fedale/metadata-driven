<?php

declare(strict_types=1);

namespace App\Tests\Functional\Module\User;

use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Tests\Support\SignsIn;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * /account: the signed-in user's name and password.
 */
final class AccountControllerTest extends WebTestCase
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

    public function testRenamesTheAccount(): void
    {
        self::signIn($this->client, 'anna@example.com');

        $this->client->request('GET', '/account');
        $this->client->submitForm('Save', ['profile[first_name]' => 'Anna', 'profile[last_name]' => 'Magnani']);
        self::assertResponseRedirects('/account', 303);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.md-topbar__user', 'Anna Magnani');
    }

    public function testChangesThePasswordKeepingThisSessionAndEndingTheOthers(): void
    {
        self::signIn($this->client, 'anna@example.com');
        $this->client->request('GET', '/');
        // Anna's session on another device: a session of its own.
        $before = $this->client->getCookieJar()->get('MOCKSESSID');
        self::assertInstanceOf(Cookie::class, $before);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser(self::getContainer()->get(UserProvider::class)->loadUserByIdentifier('anna@example.com'));
        self::assertNotSame($before->getValue(), $this->client->getCookieJar()->get('MOCKSESSID')?->getValue());

        $this->client->request('GET', '/account');
        $this->client->submitForm('Change the password', [
            'password[current]' => self::PASSWORD,
            'password[new][first]' => self::NEW_PASSWORD,
            'password[new][second]' => self::NEW_PASSWORD,
        ]);
        self::assertResponseRedirects('/account', 303);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful('This session goes on.');

        // Only the other device's cookie, as that device would send.
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($before);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login', 302, 'The other one ended.');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => 'anna@example.com', 'password' => self::NEW_PASSWORD]);
        self::assertResponseRedirects('/', 302);
    }

    public function testRefusesAWrongCurrentPasswordOrTwoNewOnesThatDiffer(): void
    {
        self::signIn($this->client, 'anna@example.com');

        $this->client->request('GET', '/account');
        $this->client->submitForm('Change the password', [
            'password[current]' => 'not her password at all',
            'password[new][first]' => self::NEW_PASSWORD,
            'password[new][second]' => self::NEW_PASSWORD,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'This is not the password of the account.');

        $this->client->submitForm('Change the password', [
            'password[current]' => self::PASSWORD,
            'password[new][first]' => self::NEW_PASSWORD,
            'password[new][second]' => 'something else entirely',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'The two new passwords differ.');
    }

    /**
     * A session left open is no way to guess the password: five tries a quarter of an hour.
     */
    public function testLimitsTheTriesAtTheCurrentPassword(): void
    {
        self::signIn($this->client, 'anna@example.com');
        $fields = static fn (string $current): array => [
            'password[current]' => $current,
            'password[new][first]' => self::NEW_PASSWORD,
            'password[new][second]' => self::NEW_PASSWORD,
        ];
        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('GET', '/account');
            $this->client->submitForm('Change the password', $fields('not her password at all'));
        }

        $this->client->request('GET', '/account');
        $this->client->submitForm('Change the password', $fields(self::PASSWORD));

        self::assertSelectorTextContains('main', 'Too many tries');
    }

    public function testAnOperatorHasTheirAccountOnTheConsoleToo(): void
    {
        self::createUser('olga@example.com');
        self::getContainer()->get(UserManagerInterface::class)->setOperator('olga@example.com', true);
        $this->client->setServerParameter('HTTP_HOST', 'console.localhost');
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => 'olga@example.com', 'password' => self::PASSWORD]);

        $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'olga@example.com');
    }

    private function clearLimits(): void
    {
        $limits = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $limits);
        $limits->clear();
    }
}
