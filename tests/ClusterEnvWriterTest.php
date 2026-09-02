<?php

declare(strict_types=1);

namespace Tests;

use AdGo\Cluster\ClusterEnvWriter;
use PHPUnit\Framework\TestCase;

/**
 * Extracted out of SettingsController (2026-08-31) - see
 * SettingsExportCryptoTest's own docblock for why these extractions
 * happen one cohesive, independently-testable piece at a time rather than
 * as a single large refactor.
 *
 * Runs against the REAL ROOTPATH/.env, backed up and restored around each
 * test - writeLines() hardcodes ROOTPATH internally, same "framework
 * constant, fixed at bootstrap, not injectable" constraint
 * NodeRegistryEnvLockTest's own docblock explains for Cluster::
 * writeNodeRegistryEnv() (the near-identical peer-sync-side counterpart
 * to this admin-UI-side writer).
 */
final class ClusterEnvWriterTest extends TestCase
{
    private string $envPath;
    private ?string $originalEnv = null;
    private bool $envPreexisted = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = rtrim(ROOTPATH, '/\\') . '/.env';
        if (is_file($this->envPath)) {
            $this->envPreexisted = true;
            $this->originalEnv   = file_get_contents($this->envPath);
        }
    }

    protected function tearDown(): void
    {
        if ($this->envPreexisted) {
            file_put_contents($this->envPath, $this->originalEnv);
        } elseif (is_file($this->envPath)) {
            unlink($this->envPath);
        }

        parent::tearDown();
    }

    public function testWriteLinesAddsANewKeyAndPreservesExistingLines(): void
    {
        file_put_contents($this->envPath, "app.baseURL = \"https://example.test/\"\n");

        $ok = ClusterEnvWriter::writeLines(['cluster.thisNode' => 'h1q']);

        $this->assertTrue($ok);
        $final = file_get_contents($this->envPath);
        $this->assertStringContainsString('app.baseURL = "https://example.test/"', $final);
        $this->assertStringContainsString('cluster.thisNode = h1q', $final);
    }

    public function testWriteLinesReplacesAnExistingKeyInPlaceRatherThanDuplicatingIt(): void
    {
        file_put_contents($this->envPath, "cluster.thisNode = old-name\ncluster.secretToken = \"keep-me\"\n");

        $ok = ClusterEnvWriter::writeLines(['cluster.thisNode' => 'new-name']);

        $this->assertTrue($ok);
        $final = file_get_contents($this->envPath);
        $this->assertSame(1, substr_count($final, 'cluster.thisNode'));
        $this->assertStringContainsString('cluster.thisNode = new-name', $final);
        $this->assertStringNotContainsString('old-name', $final);
        $this->assertStringContainsString('cluster.secretToken = "keep-me"', $final);
    }

    public function testWriteLinesReturnsFalseWhenTheEnvFileDoesNotExist(): void
    {
        if (is_file($this->envPath)) {
            unlink($this->envPath);
        }

        $this->assertFalse(ClusterEnvWriter::writeLines(['cluster.thisNode' => 'h1q']));
    }

    public function testWriteNodesFormatsEntriesWithAndWithoutAPublicKey(): void
    {
        file_put_contents($this->envPath, "cluster.thisNode = h1q\n");

        $ok = ClusterEnvWriter::writeNodes([
            'bak' => ['baseURL' => 'https://bak.example/', 'type' => 'public', 'publicKey' => 'abc123'],
            'res' => ['baseURL' => 'https://res.example/', 'type' => 'nat', 'publicKey' => ''],
        ]);

        $this->assertTrue($ok);
        $final = file_get_contents($this->envPath);
        $this->assertStringContainsString(
            'cluster.nodes = "bak|https://bak.example/|public|abc123,res|https://res.example/|nat"',
            $final
        );
    }

    public function testWriteNodesRefreshesTheSharedConfigSingletonWithinTheSameRequest(): void
    {
        file_put_contents($this->envPath, "cluster.thisNode = h1q\n");

        // config('Cluster') is a shared singleton for the life of the
        // process - this proves writeNodes() updates it in place rather
        // than leaving a caller later in the same request holding a
        // pre-write snapshot (a fresh process reading .env directly would
        // pick up the write regardless; this is specifically about NOT
        // needing one).
        $config = config('Cluster');

        $ok = ClusterEnvWriter::writeNodes([
            'bak' => ['baseURL' => 'https://bak.example', 'type' => 'public', 'publicKey' => ''],
        ]);

        $this->assertTrue($ok);
        $this->assertArrayHasKey('bak', $config->nodes);
        // Also normalizes baseURL to a single trailing slash, regardless
        // of what the caller passed in.
        $this->assertSame('https://bak.example/', $config->nodes['bak']['baseURL']);
    }
}
