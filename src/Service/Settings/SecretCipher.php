<?php

namespace App\Service\Settings;

/**
 * Encrypts secrets before they enter the database, and decrypts them on the way out.
 *
 * Values are stored as `enc:v1:<base64(nonce ‖ ciphertext)>` using libsodium's
 * secretbox. A value without that prefix is **refused** by {@see PlatformSettings}
 * for a secret row, so a half-migrated setting fails loudly instead of being saved
 * in the clear.
 *
 * The key is `APP_ENCRYPTION_KEY` (base64, 32 bytes) and is deliberately separate
 * from `APP_SECRET`: `APP_SECRET` also signs sessions and CSRF tokens, and rotating
 * it — which you should do after any suspected leak — must not turn every stored
 * key into garbage.
 *
 * If the key is absent the app still runs: everything falls back to the environment
 * and the settings screens refuse to save, telling the operator what to add. Losing
 * a key must never be the reason nobody can sign in.
 */
final class SecretCipher
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';

    private ?string $key = null;

    public function __construct(#[\SensitiveParameter] string $appEncryptionKey = '')
    {
        $raw = trim($appEncryptionKey);

        if ($raw !== '') {
            $decoded = base64_decode($raw, true);
            $this->key = \is_string($decoded) && \strlen($decoded) === 32
                ? $decoded
                : hash('sha256', $raw, true); // tolerate a passphrase instead of a base64 key
        }
    }

    public function canEncrypt(): bool
    {
        return $this->key !== null;
    }

    public function isEncrypted(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::PREFIX);
    }

    /**
     * @throws \RuntimeException when no encryption key is configured — never returns plaintext
     */
    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $key = $this->requireKey();

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new \RuntimeException('Could not encrypt the value.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Returns null when the value cannot be read — a missing key, a truncated
     * ciphertext, or a value written with a different key. Callers treat null as
     * "not configured" and say so; nothing silently degrades to plaintext.
     */
    public function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!$this->isEncrypted($value)) {
            return null;
        }

        $key = $this->key;
        if ($key === null) {
            return null;
        }

        $binary = base64_decode(substr($value, \strlen(self::PREFIX)), true);
        if (!\is_string($binary) || \strlen($binary) < 29) {
            return null;
        }

        $iv = substr($binary, 0, 12);
        $tag = substr($binary, 12, 16);
        $ciphertext = substr($binary, 28);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? null : $plaintext;
    }

    /** A masked hint for the screen: `sk_live_…3f9c`. Never the value itself. */
    public function hint(#[\SensitiveParameter] ?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }

        $visible = 4;
        if (\strlen($plaintext) <= $visible * 2) {
            return str_repeat('•', \strlen($plaintext));
        }

        $head = str_contains($plaintext, '_') ? strstr($plaintext, '_', true) . '_' : substr($plaintext, 0, 4);

        return $head . '…' . substr($plaintext, -$visible);
    }

    /** Generates a key in the format the env var expects — used by the setup command. */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    private function requireKey(): string
    {
        if ($this->key === null) {
            throw new \RuntimeException(
                'APP_ENCRYPTION_KEY is not set, so secrets cannot be saved. Generate one with '
                . '"php bin/console app:platform:make-key" and add it to the server environment (or .env.local).'
            );
        }

        return $this->key;
    }
}
