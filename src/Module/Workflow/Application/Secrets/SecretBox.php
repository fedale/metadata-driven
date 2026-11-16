<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Secrets;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * libsodium's secret box with WORKFLOW_SECRETS_KEY (32 bytes, base64): a value in, a random nonce
 * and the cipher text out, base64.
 */
final class SecretBox
{
    public function __construct(
        #[Autowire(param: 'app.workflow.secrets_key')]
        private readonly ?string $key,
    ) {
    }

    /**
     * A new key, base64: for WORKFLOW_SECRETS_KEY.
     */
    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    /**
     * @throws SecretsKeyMissing
     */
    public function seal(string $value): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($value, $nonce, $this->key()));
    }

    /**
     * @throws SecretsKeyMissing
     * @throws \UnexpectedValueException a value sealed with another key, or damaged
     */
    public function open(string $sealed): string
    {
        $raw = base64_decode($sealed, true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \UnexpectedValueException('A secret is damaged.');
        }
        $value = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());
        if (false === $value) {
            throw new \UnexpectedValueException('A secret cannot be read with WORKFLOW_SECRETS_KEY: was it written with another key?');
        }

        return $value;
    }

    private function key(): string
    {
        $key = null === $this->key ? false : base64_decode($this->key, true);
        if (false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw SecretsKeyMissing::create();
        }

        return $key;
    }
}
