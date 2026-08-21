<?php
namespace GlpiPlugin\Ticketeamer;

use RuntimeException;

final class Crypto
{
    private const PREFIX = 'v1:';

    private static function key(): string
    {
        $value = getenv('GLPI_TEAMS_BRIDGE_ENCRYPTION_KEY');
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('GLPI_TEAMS_BRIDGE_ENCRYPTION_KEY is not configured.');
        }

        return hash('sha256', $value, true);
    }

    public static function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, self::key());
        return self::PREFIX . base64_encode($nonce . $ciphertext);
    }

    public static function decrypt(string $payload): string
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            throw new RuntimeException('Unsupported encrypted value format.');
        }

        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Invalid encrypted value.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, self::key());
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt value.');
        }

        return $plaintext;
    }
}
