<?php

declare(strict_types=1);

namespace AdGo\Cluster;

/**
 * Encrypts/decrypts a Settings export payload (SettingsController::
 * exportSettings()'s own $payload - RSA private key, secretToken and
 * every node's plaintext credentials, all in one JSON blob) with a
 * password the user types at export time, so the downloaded file is safe
 * to move around (email, a shared drive, ...) without carrying the whole
 * mesh's credentials in the clear.
 *
 * AES-256-GCM via ext-openssl - already a hard dependency of this project
 * (Config\Cluster::$signingPrivateKey's own RSA keypairs are generated
 * through the very same extension), so this needs nothing new installed.
 * PBKDF2-SHA256 (ext-hash, always compiled into PHP) turns the password
 * into a 256-bit key; a fresh random salt AND a fresh random 96-bit GCM
 * nonce every call (never reused - reusing a nonce under the same key
 * breaks GCM's confidentiality guarantee) means two exports of the
 * identical payload with the identical password still produce completely
 * different ciphertext. GCM's own 128-bit auth tag (not a separate HMAC)
 * is what lets decrypt() below detect a wrong password OR a tampered/
 * corrupted file - openssl_decrypt() just returns false the moment the
 * tag doesn't verify, before any plaintext is trusted.
 *
 * Extracted out of SettingsController (2026-08-30) - pure functions, no
 * framework dependency at all, previously the only piece of this
 * project's crypto with zero test coverage precisely because it lived
 * inside a 2000+ line controller nothing else exercised in isolation.
 */
final class SettingsExportCrypto
{
    public static function encrypt(array $payload, string $password): array
    {
        $iterations = 100000;
        $salt       = random_bytes(16);
        $iv         = random_bytes(12);
        $key        = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);

        $tag        = '';
        $ciphertext = openssl_encrypt(
            json_encode($payload, JSON_UNESCAPED_SLASHES),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        return [
            'encrypted'  => true,
            'cipher'     => 'aes-256-gcm',
            'kdf'        => 'pbkdf2-sha256',
            'iterations' => $iterations,
            'salt'       => base64_encode($salt),
            'iv'         => base64_encode($iv),
            'tag'        => base64_encode($tag),
            'data'       => base64_encode((string) $ciphertext),
        ];
    }

    /**
     * The other half of encrypt() above - importSettings()/importCluster()
     * both call this the instant they see an uploaded file's top-level
     * "encrypted": true (added by this same pair, so any file either
     * export flow produced round-trips through whichever import flow
     * makes sense for the importing node's own current state, exactly
     * like an unencrypted export already does). Returns null - never
     * throws - for EVERY failure case (malformed envelope, wrong
     * password, a file that's been tampered with after export): the
     * caller can't tell which happened, on purpose, so an import failure
     * never leaks whether a guessed password was "close".
     */
    public static function decrypt(array $envelope, string $password): ?array
    {
        if ($password === '') {
            return null;
        }

        $salt = base64_decode((string) ($envelope['salt'] ?? ''), true);
        $iv   = base64_decode((string) ($envelope['iv'] ?? ''), true);
        $tag  = base64_decode((string) ($envelope['tag'] ?? ''), true);
        $data = base64_decode((string) ($envelope['data'] ?? ''), true);
        if ($salt === false || $iv === false || $tag === false || $data === false
            || $salt === '' || $iv === '' || $tag === '') {
            return null;
        }

        $iterations = (int) ($envelope['iterations'] ?? 0);
        if ($iterations < 1) {
            return null;
        }

        $key       = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
        $plaintext = openssl_decrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            return null;
        }

        $decoded = json_decode($plaintext, true);

        return is_array($decoded) ? $decoded : null;
    }
}
