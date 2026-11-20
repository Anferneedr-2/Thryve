<?php

namespace App\Support;

use App\Ai\AgentService;
use InvalidArgumentException;

/**
 * Carries one chatbot's access key to another installation inside a file that is useless without a passphrase.
 *
 * The passphrase is chosen by the person at their own keyboard and is never stored or logged. The key is encrypted with
 * AES-256-GCM using a key derived from the passphrase (PBKDF2-SHA256, 600,000 rounds, random salt), and the chatbot's short
 * name and the key's public fingerprint are bound to the ciphertext, so a file that was tampered with or swapped is refused.
 */
final class AgentKeyTransfer
{
    public const FORMAT = 'thryve-agent-key';

    private const ROUNDS = 600000;

    public static function seal(string $slug, string $key, string $passphrase): array
    {
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $fingerprint = AgentService::fingerprint($key);
        $tag = '';
        $cipher = openssl_encrypt($key, 'aes-256-gcm', self::derive($passphrase, $salt), OPENSSL_RAW_DATA, $iv, $tag, self::bound($slug, $fingerprint), 16);
        if ($cipher === false) {
            throw new \RuntimeException('The key could not be encrypted on this server (OpenSSL is needed).');
        }

        return [
            'format' => self::FORMAT, 'version' => 1, 'agent' => $slug, 'fingerprint' => $fingerprint, 'created_at' => now()->toIso8601String(),
            'kdf' => 'pbkdf2-sha256', 'rounds' => self::ROUNDS, 'cipher' => 'aes-256-gcm',
            'salt' => base64_encode($salt), 'iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'data' => base64_encode($cipher),
        ];
    }

    /** @return array{agent: string, fingerprint: string, key: string} */
    public static function open(array $file, string $passphrase): array
    {
        if (($file['format'] ?? null) !== self::FORMAT || ($file['version'] ?? null) !== 1 || ($file['cipher'] ?? '') !== 'aes-256-gcm' || ($file['kdf'] ?? '') !== 'pbkdf2-sha256'
            || ! isset($file['agent'], $file['fingerprint'], $file['salt'], $file['iv'], $file['tag'], $file['data']) || (int) ($file['rounds'] ?? 0) < 100000) {
            throw new InvalidArgumentException('This is not a chatbot key file made by Thryve.');
        }
        $key = openssl_decrypt((string) base64_decode($file['data'], true), 'aes-256-gcm', self::derive($passphrase, (string) base64_decode($file['salt'], true), (int) $file['rounds']),
            OPENSSL_RAW_DATA, (string) base64_decode($file['iv'], true), (string) base64_decode($file['tag'], true), self::bound($file['agent'], $file['fingerprint']));
        if ($key === false || AgentService::fingerprint($key) !== $file['fingerprint']) {
            // Deliberately one message: it does not say whether the passphrase or the file was the problem.
            throw new InvalidArgumentException('The passphrase is wrong, or the file was changed.');
        }

        return ['agent' => $file['agent'], 'fingerprint' => $file['fingerprint'], 'key' => $key];
    }

    private static function derive(string $passphrase, string $salt, int $rounds = self::ROUNDS): string
    {
        return hash_pbkdf2('sha256', $passphrase, $salt, $rounds, 32, true);
    }

    private static function bound(string $slug, string $fingerprint): string
    {
        return self::FORMAT.'|'.$slug.'|'.$fingerprint;
    }
}
