<?php

declare(strict_types=1);

namespace Tests;

use AdGo\Cluster\SettingsExportCrypto;
use PHPUnit\Framework\TestCase;

/**
 * Extracted out of SettingsController (2026-08-30) specifically because it
 * was the one piece of this project's crypto with zero test coverage,
 * precisely because it lived inside a 2000+ line controller nothing else
 * exercised in isolation. Covers the properties that actually matter for
 * something protecting an export file full of every node's plaintext
 * credentials: a correct password round-trips the payload exactly, a
 * wrong password or ANY tampering is rejected (GCM's auth tag, not a
 * separate check), and two exports of the same payload+password never
 * produce identical ciphertext (fresh salt/IV every call - a repeat would
 * mean nonce reuse, which breaks AES-GCM's confidentiality guarantee).
 */
final class SettingsExportCryptoTest extends TestCase
{
    private array $payload = [
        'secretToken'       => 'shared-secret',
        'signingPrivateKey' => 'base64-of-a-real-rsa-key',
        'nodes'             => ['h1q' => ['baseURL' => 'https://h1q.example/', 'dbPassword' => 'super-secret']],
    ];

    public function testEncryptThenDecryptRoundTripsTheExactPayload(): void
    {
        $envelope = SettingsExportCrypto::encrypt($this->payload, 'correct horse battery staple');

        $this->assertSame($this->payload, SettingsExportCrypto::decrypt($envelope, 'correct horse battery staple'));
    }

    public function testDecryptWithTheWrongPasswordReturnsNull(): void
    {
        $envelope = SettingsExportCrypto::encrypt($this->payload, 'the-real-password');

        $this->assertNull(SettingsExportCrypto::decrypt($envelope, 'a-guessed-password'));
    }

    public function testDecryptWithAnEmptyPasswordReturnsNullWithoutEvenAttempting(): void
    {
        $envelope = SettingsExportCrypto::encrypt($this->payload, 'the-real-password');

        $this->assertNull(SettingsExportCrypto::decrypt($envelope, ''));
    }

    public function testTamperedCiphertextIsRejectedByTheAuthTagNotJustGarbageOutput(): void
    {
        $envelope = SettingsExportCrypto::encrypt($this->payload, 'the-real-password');

        // Flip one byte of the ciphertext - GCM's auth tag must catch
        // this, not silently decrypt to corrupted-but-parseable JSON.
        $data                = base64_decode($envelope['data'], true);
        $data[0]             = chr(ord($data[0]) ^ 0xFF);
        $envelope['data']    = base64_encode($data);

        $this->assertNull(SettingsExportCrypto::decrypt($envelope, 'the-real-password'));
    }

    public function testTamperedAuthTagIsRejected(): void
    {
        $envelope = SettingsExportCrypto::encrypt($this->payload, 'the-real-password');

        $tag             = base64_decode($envelope['tag'], true);
        $tag[0]          = chr(ord($tag[0]) ^ 0xFF);
        $envelope['tag'] = base64_encode($tag);

        $this->assertNull(SettingsExportCrypto::decrypt($envelope, 'the-real-password'));
    }

    /**
     * @return iterable<string, array{0: array}>
     */
    public static function malformedEnvelopeProvider(): iterable
    {
        yield 'missing salt' => [['iv' => 'aXY=', 'tag' => 'dGFn', 'data' => 'ZGF0YQ==', 'iterations' => 100000]];
        yield 'missing iterations' => [['salt' => 'c2FsdA==', 'iv' => 'aXY=', 'tag' => 'dGFn', 'data' => 'ZGF0YQ==']];
        yield 'zero iterations' => [['salt' => 'c2FsdA==', 'iv' => 'aXY=', 'tag' => 'dGFn', 'data' => 'ZGF0YQ==', 'iterations' => 0]];
        yield 'not base64' => [['salt' => '***not-base64***', 'iv' => 'aXY=', 'tag' => 'dGFn', 'data' => 'ZGF0YQ==', 'iterations' => 100000]];
        yield 'completely empty' => [[]];
    }

    /**
     * @dataProvider malformedEnvelopeProvider
     */
    public function testMalformedEnvelopeReturnsNullInsteadOfErroring(array $envelope): void
    {
        $this->assertNull(SettingsExportCrypto::decrypt($envelope, 'any-password'));
    }

    public function testTwoEncryptionsOfTheSamePayloadAndPasswordNeverProduceIdenticalCiphertext(): void
    {
        $first  = SettingsExportCrypto::encrypt($this->payload, 'same-password');
        $second = SettingsExportCrypto::encrypt($this->payload, 'same-password');

        $this->assertNotSame($first['salt'], $second['salt']);
        $this->assertNotSame($first['iv'], $second['iv']);
        $this->assertNotSame($first['data'], $second['data']);

        // Both still independently decrypt correctly with the fresh
        // salt/IV each carries.
        $this->assertSame($this->payload, SettingsExportCrypto::decrypt($first, 'same-password'));
        $this->assertSame($this->payload, SettingsExportCrypto::decrypt($second, 'same-password'));
    }
}
