<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Notification;

use App\Module\Notification\Contract\Dto\NotificationMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationMessageTest extends TestCase
{
    public function testTrimsAndCutsItsTexts(): void
    {
        $message = new NotificationMessage('  Overdue  ', str_repeat('x', 3000), '/o/invoice/1');

        self::assertSame('Overdue', $message->title);
        self::assertSame(NotificationMessage::MAX_TEXT, mb_strlen((string) $message->text));
        self::assertNull((new NotificationMessage('Overdue', '   '))->text);
        self::assertSame(NotificationMessage::MAX_TITLE, mb_strlen((new NotificationMessage(str_repeat('é', 300)))->title));
    }

    public function testATitleIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NotificationMessage('   ');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function linksAway(): iterable
    {
        yield 'an address' => ['https://attacker.example/'];
        yield 'protocol-relative' => ['//attacker.example/'];
        yield 'a backslash' => ['/\\attacker.example'];
        yield 'relative' => ['o/invoice/1'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'with a space' => ['/o/invoice /1'];
    }

    #[DataProvider('linksAway')]
    public function testALinkIsAPathOfTheWorkspace(string $link): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NotificationMessage('Overdue', null, $link);
    }

    public function testAPathWithAQueryIsALink(): void
    {
        self::assertSame('/o/invoice?status=sent', (new NotificationMessage('Overdue', null, '/o/invoice?status=sent'))->link);
    }
}
