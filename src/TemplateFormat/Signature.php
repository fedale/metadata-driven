<?php

declare(strict_types=1);

namespace App\TemplateFormat;

/**
 * Ed25519 signatures of index entries (libsodium). A signature covers the format, the
 * template's name, its version and its archive's sha256: it vouches for that archive as that
 * version, and cannot be moved to another entry.
 *
 * Keys and signatures are written in base64.
 */
final class Signature
{
    /**
     * @return array{public: string, secret: string}
     */
    public static function keyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    /**
     * @throws \InvalidArgumentException the secret key is not one
     */
    public static function sign(IndexEntry $entry, string $secretKey): string
    {
        $key = base64_decode(trim($secretKey), true);
        if (false === $key || \SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== \strlen($key)) {
            throw new \InvalidArgumentException('This is not an Ed25519 secret key in base64.');
        }

        return base64_encode(sodium_crypto_sign_detached(self::message($entry), $key));
    }

    /**
     * Whether the entry is signed, by the holder of the secret key matching this public one.
     */
    public static function verify(IndexEntry $entry, string $publicKey): bool
    {
        $key = base64_decode(trim($publicKey), true);
        $signature = null === $entry->signature ? false : base64_decode($entry->signature, true);
        if (false === $key || \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($key)
            || false === $signature || \SODIUM_CRYPTO_SIGN_BYTES !== \strlen($signature)) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, self::message($entry), $key);
    }

    public static function isPublicKey(string $publicKey): bool
    {
        $key = base64_decode(trim($publicKey), true);

        return false !== $key && \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === \strlen($key);
    }

    private static function message(IndexEntry $entry): string
    {
        return implode("\n", [TemplateIndex::FORMAT, $entry->name, $entry->version, $entry->sha256]);
    }
}
