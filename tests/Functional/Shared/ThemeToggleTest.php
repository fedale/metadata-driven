<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * The light / dark toggle: the system's theme until the md_theme cookie forces one, set on
 * <html> by the server so that it shows from the first paint.
 */
final class ThemeToggleTest extends WebTestCase
{
    public function testFollowsTheSystemUntilAThemeIsForced(): void
    {
        $client = self::createClient();

        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('html[data-gv-theme]');
        // The sign-in page has no top bar: the toggle sits in a corner.
        self::assertSelectorExists('button.md-theme.md-theme--floating[data-controller="theme-toggle"]');

        $client->request('GET', '/password/forgot');
        self::assertSelectorExists('.md-topbar > button.md-theme[data-action="theme-toggle#toggle"]');
    }

    public function testTheCookieForcesTheTheme(): void
    {
        $client = self::createClient();

        foreach (['dark', 'light'] as $theme) {
            $client->getCookieJar()->set(new Cookie('md_theme', $theme));
            $client->request('GET', '/login');
            self::assertSelectorExists(\sprintf('html[data-gv-theme="%s"]', $theme));
        }
    }

    public function testIgnoresAnUnknownTheme(): void
    {
        $client = self::createClient();
        $client->getCookieJar()->set(new Cookie('md_theme', '"><script>'));

        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('html[data-gv-theme]');
    }
}
