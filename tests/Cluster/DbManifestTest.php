<?php

declare(strict_types=1);

namespace Tests\Cluster;

use AdGo\Cluster\Config\Cluster as ClusterConfig;
use AdGo\Cluster\DbManifest;
use PHPUnit\Framework\TestCase;

/**
 * DbManifest is the DB-sync equivalent of the file manifest: the single
 * source of truth SyncDbCommand's scan compares a freshly-computed hash
 * against, and the row-level LWW comparison point
 * DbSyncSchema::applyIncomingCommand() reads back via `timestamp`. The one
 * behavior worth a real test, not just trusting the docblock: `recordedAt`
 * is stamped by record() itself and is NOT the same value as the
 * caller-supplied `timestamp` - get()/all() must return both, unmodified
 * and un-conflated.
 *
 * Same real-directory-under-WRITEPATH approach as DeletedFilesTest - see
 * that test's own docblock for why not vfsstream.
 */
final class DbManifestTest extends TestCase
{
    private DbManifest $manifest;

    protected function setUp(): void
    {
        parent::setUp();

        $config           = new ClusterConfig();
        $config->stateDir = 'Cluster-test-' . bin2hex(random_bytes(6));
        $this->manifest    = new DbManifest($config);
    }

    protected function tearDown(): void
    {
        $path = $this->manifest->path();
        if (is_file($path)) {
            unlink($path);
        }
        $dir = dirname($path);
        if (is_dir($dir)) {
            @rmdir($dir);
        }

        parent::tearDown();
    }

    public function testGetOnAMissingKeyReturnsNull(): void
    {
        $this->assertNull($this->manifest->get('users:k@1q.ro'));
    }

    public function testAllOnAMissingFileReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->manifest->all());
    }

    public function testRecordThenGetRoundTripsTheHashAndTimestamp(): void
    {
        $updatedAt = time() - 120;
        $this->manifest->record('users:k@1q.ro', ['hash' => 'abc123', 'timestamp' => $updatedAt]);

        $entry = $this->manifest->get('users:k@1q.ro');

        $this->assertNotNull($entry);
        $this->assertSame('abc123', $entry['hash']);
        // The row's own updated_at, exactly as passed in - this is the
        // value applyIncomingCommand() uses for LWW, so record() must
        // never silently overwrite it with its own wall-clock time.
        $this->assertSame($updatedAt, $entry['timestamp']);
    }

    public function testRecordStampsRecordedAtSeparatelyFromTheCallerSuppliedTimestamp(): void
    {
        $originalRowTimestamp = time() - 3600;
        $before                = time();
        $this->manifest->record('settings:Site.title', ['hash' => 'xyz', 'timestamp' => $originalRowTimestamp]);
        $after = time();

        $entry = $this->manifest->get('settings:Site.title');

        // recordedAt is THIS node's own wall-clock "when did I touch
        // this", stamped by record() itself - must be close to now, not
        // equal to the caller's much-older `timestamp`.
        $this->assertGreaterThanOrEqual($before, $entry['recordedAt']);
        $this->assertLessThanOrEqual($after, $entry['recordedAt']);
        $this->assertNotSame($originalRowTimestamp, $entry['recordedAt']);
    }

    public function testRecordOverwritesAnExistingKey(): void
    {
        $this->manifest->record('users:k@1q.ro', ['hash' => 'old', 'timestamp' => 1]);
        $this->manifest->record('users:k@1q.ro', ['hash' => 'new', 'timestamp' => 2]);

        $all = $this->manifest->all();

        $this->assertCount(1, $all);
        $this->assertSame('new', $all['users:k@1q.ro']['hash']);
    }

    public function testAllReturnsEveryRecordedKey(): void
    {
        $this->manifest->record('users:a@example.com', ['hash' => 'h1', 'timestamp' => 1]);
        $this->manifest->record('users:b@example.com', ['hash' => 'h2', 'timestamp' => 2]);

        $all = $this->manifest->all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('users:a@example.com', $all);
        $this->assertArrayHasKey('users:b@example.com', $all);
    }
}
