<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Infrastructure\Workspace\HostWorkspaceUrls;
use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\Workspace;
use App\Shared\Workspace\Workspaces;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class HostWorkspaceUrlsTest extends TestCase
{
    public function testAWorkspaceIsOnItsOwnHostTheDefaultOneOnTheBaseHost(): void
    {
        $urls = self::urls(new RequestStack(), 'https://app.example.com');

        self::assertSame('https://acme.app.example.com/invitation/abc', $urls->ofWorkspace(self::workspace('acme'), '/invitation/abc'));
        self::assertSame('https://app.example.com/', $urls->ofWorkspace(self::workspace('default')));
        self::assertSame('https://console.app.example.com/workspaces', $urls->ofConsole('/workspaces'));
    }

    public function testTheRequestTellsTheSchemeAndThePort(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('http://localhost:8000/admin/members'));
        $urls = self::urls($requests, 'https://app.example.com');

        self::assertSame('http://acme.app.example.com:8000/', $urls->ofWorkspace(self::workspace('acme')));
    }

    public function testWithoutARequestDefaultUriTellsThem(): void
    {
        self::assertSame('http://acme.app.example.com:8000/x', self::urls(new RequestStack(), 'http://localhost:8000')->ofWorkspace(self::workspace('acme'), 'x'));
        self::assertSame('https://acme.app.example.com/', self::urls(new RequestStack(), 'https://localhost:443')->ofWorkspace(self::workspace('acme')));
    }

    public function testTheCurrentWorkspaces(): void
    {
        $current = new class implements CurrentWorkspace {
            public function isSelected(): bool
            {
                return true;
            }

            public function id(): string
            {
                return 'acme-id';
            }

            public function dataSchema(): string
            {
                return 'data_acme';
            }
        };
        $workspaces = new class implements Workspaces {
            public function bySlug(string $slug): ?Workspace
            {
                return null;
            }

            public function byId(string $id): ?Workspace
            {
                return 'acme-id' === $id ? new Workspace('acme-id', 'acme', 'Acme', 2, 'data_acme', 'rbac_acme', true) : null;
            }

            public function all(): array
            {
                return [];
            }
        };

        self::assertSame('https://acme.app.example.com/notifications', (new HostWorkspaceUrls($current, $workspaces, new RequestStack(), 'app.example.com', 'default', 'console', 'https://app.example.com'))->ofCurrent('/notifications'));
    }

    private static function urls(RequestStack $requests, string $defaultUri): HostWorkspaceUrls
    {
        return new HostWorkspaceUrls(self::createStub(CurrentWorkspace::class), self::createStub(Workspaces::class), $requests, 'app.example.com', 'default', 'console', $defaultUri);
    }

    private static function workspace(string $slug): Workspace
    {
        return new Workspace($slug.'-id', $slug, ucfirst($slug), 1, 'data_'.$slug, 'rbac_'.$slug, true);
    }
}
