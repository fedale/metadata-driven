<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The web, in the tests: every request the HTTP client makes is answered from the files served
 * here (config/packages/framework.yaml, when@test), a 404 otherwise.
 */
final class FakeTemplateServer
{
    /** @var array<string, string> url => body */
    private static array $files = [];

    /** @var list<string> */
    private static array $requested = [];

    public static function reset(): void
    {
        self::$files = [];
        self::$requested = [];
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

        return isset(self::$files[$url]) ? new MockResponse(self::$files[$url]) : new MockResponse('Not found', ['http_code' => 404]);
    }
}
