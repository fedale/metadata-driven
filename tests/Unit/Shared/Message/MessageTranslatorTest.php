<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Message;

use App\Shared\Infrastructure\Message\SymfonyMessageTranslator;
use App\Shared\Message\Message;
use App\Shared\Message\MessageList;
use App\Shared\Message\MessageTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class MessageTranslatorTest extends TestCase
{
    private MessageTranslator $messages;

    protected function setUp(): void
    {
        $translator = new Translator('en');
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'metadata.still_used' => '%what% non può essere archiviato, è ancora usato: %usages%.',
            'metadata.usage.form' => 'il modulo %form% lo scrive',
        ], 'it', MessageTranslator::DOMAIN);
        $this->messages = new SymfonyMessageTranslator($translator);
    }

    public function testCastingToStringGivesTheEnglishText(): void
    {
        self::assertSame('Object "pet" does not exist.', (string) new Message('metadata.object_not_found', 'Object "%name%" does not exist.', ['name' => 'pet']));
    }

    public function testTranslatesTheMessageAndTheMessagesItHolds(): void
    {
        self::assertSame('pet non può essere archiviato, è ancora usato: il modulo registration lo scrive; contact.', $this->messages->trans($this->stillUsed(), 'it'));
    }

    public function testFallsBackOnTheEnglishTextOfAKeyWithNoTranslation(): void
    {
        self::assertSame('pet cannot be archived, it is still used: form registration writes it; contact.', $this->messages->trans($this->stillUsed(), 'en'));
        self::assertSame('Object "pet" does not exist.', $this->messages->trans(new Message('metadata.object_not_found', 'Object "%name%" does not exist.', ['name' => 'pet']), 'it'));
    }

    public function testShowsAPlainStringAsItIs(): void
    {
        self::assertSame(['Questo valore non dovrebbe essere vuoto.'], $this->messages->transAll(['Questo valore non dovrebbe essere vuoto.'], 'it'));
    }

    private function stillUsed(): Message
    {
        return new Message('metadata.still_used', '%what% cannot be archived, it is still used: %usages%.', [
            'what' => 'pet',
            'usages' => new MessageList([new Message('metadata.usage.form', 'form %form% writes it', ['form' => 'registration']), 'contact']),
        ]);
    }
}
