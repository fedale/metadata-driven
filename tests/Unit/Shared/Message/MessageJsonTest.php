<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Message;

use App\Shared\Message\Message;
use App\Shared\Message\MessageJson;
use App\Shared\Message\MessageList;
use PHPUnit\Framework\TestCase;

final class MessageJsonTest extends TestCase
{
    public function testAMessageComesBackThroughJsonWithItsKeyAndParameters(): void
    {
        $reason = new Message('record.import.line', 'Line %line%: %reason%', [
            'line' => 4,
            'reason' => new MessageList([new Message('field_type.value.not_number', 'This value should be a number.'), 'Too long.'], ' '),
        ]);

        $stored = json_decode(json_encode(MessageJson::encode($reason), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        self::assertEquals($reason, MessageJson::decode($stored));
        self::assertSame('Line 4: This value should be a number. Too long.', (string) MessageJson::decode($stored));
    }

    public function testAStringStaysAString(): void
    {
        // A reason stored before reasons were messages.
        self::assertSame('Line 2: name: This value is required.', MessageJson::decode(MessageJson::encode('Line 2: name: This value is required.')));
    }

    public function testWhatNoMessageWasEncodedToDecodesToNull(): void
    {
        self::assertNull(MessageJson::decode(42));
        self::assertNull(MessageJson::decode(['text' => 'No key.']));
    }
}
