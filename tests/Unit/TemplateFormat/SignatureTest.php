<?php

declare(strict_types=1);

namespace App\Tests\Unit\TemplateFormat;

use App\TemplateFormat\IndexEntry;
use App\TemplateFormat\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    public function testAnEntrySignedByTheKeyHolderVerifies(): void
    {
        $keys = Signature::keyPair();
        $entry = self::entry();
        $signed = self::entry(signature: Signature::sign($entry, $keys['secret']));

        self::assertTrue(Signature::verify($signed, $keys['public']));
        self::assertTrue(Signature::isPublicKey($keys['public']));
    }

    public function testASignatureVouchesForThatEntryOnly(): void
    {
        $keys = Signature::keyPair();
        $signature = Signature::sign(self::entry(), $keys['secret']);

        // The yanked flag flipped, another archive, another version, another key, no signature:
        // none verifies.
        self::assertFalse(Signature::verify(new IndexEntry('acme/shop', '1.0.0', 'https://templates.acme.example/acme-shop.tar.gz', str_repeat('a', 64), $signature, yanked: true), $keys['public']));
        self::assertFalse(Signature::verify(self::entry(sha256: str_repeat('b', 64), signature: $signature), $keys['public']));
        self::assertFalse(Signature::verify(self::entry(version: '2.0.0', signature: $signature), $keys['public']));
        self::assertFalse(Signature::verify(self::entry(signature: $signature), Signature::keyPair()['public']));
        self::assertFalse(Signature::verify(self::entry(), $keys['public']));
        self::assertFalse(Signature::verify(self::entry(signature: 'not base64!'), $keys['public']));
    }

    private static function entry(string $version = '1.0.0', string $sha256 = '', ?string $signature = null): IndexEntry
    {
        return new IndexEntry('acme/shop', $version, 'https://templates.acme.example/acme-shop.tar.gz', '' === $sha256 ? str_repeat('a', 64) : $sha256, $signature);
    }
}
