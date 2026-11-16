<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The web, in the tests: every request the HTTP client makes is answered from the files served
 * here (config/packages/framework.yaml, when@test), a 404 otherwise.
 */
final class FakePackageServer
{
    /** @var array<string, string> url => body */
    private static array $files = [];

    /** @var list<string> */
    private static array $requested = [];

    /** @var array<string, callable(string, array<string, mixed>): MockResponse> url => answer */
    private static array $answers = [];

    /** @var list<array{string, string, array<string, mixed>}> method, url, options */
    private static array $requests = [];

    public static function reset(): void
    {
        self::$files = [];
        self::$requested = [];
        self::$answers = [];
        self::$requests = [];
        self::$any = null;
    }

    /**
     * An answer of its own for a url: what a workflow's HTTP request gets.
     *
     * @param callable(string, array<string, mixed>): MockResponse $answer method, options => response
     */
    public static function answer(string $url, callable $answer): void
    {
        self::$answers[$url] = $answer;
    }

    /** @var (callable(string, array<string, mixed>): MockResponse)|null for any url without one of its own */
    private static $any;

    /**
     * An answer for any url with none of its own and no file served.
     *
     * @param callable(string, array<string, mixed>): MockResponse $answer
     */
    public static function answerAny(callable $answer): void
    {
        self::$any = $answer;
    }

    /**
     * @return list<array{string, string, array<string, mixed>}> method, url, options
     */
    public static function requests(): array
    {
        return self::$requests;
    }

    public static function serve(string $url, string $body): void
    {
        self::$files[$url] = $body;
    }

    /**
     * @return list<string>
     */
    public static function requested(): array
    {
        return self::$requested;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        self::$requested[] = $url;
        self::$requests[] = [$method, $url, $options];
        if (isset(self::$answers[$url])) {
            return (self::$answers[$url])($method, $options);
        }
        if (!isset(self::$files[$url]) && null !== self::$any) {
            return (self::$any)($method, $options);
        }

        return isset(self::$files[$url]) ? new MockResponse(self::$files[$url]) : new MockResponse('Not found', ['http_code' => 404]);
    }
}
