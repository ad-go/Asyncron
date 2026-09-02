<?php

declare(strict_types=1);

namespace Tests\Cluster;

use AdGo\Cluster\Config\Cluster as ClusterConfig;
use AdGo\Cluster\DeletedFiles;
use PHPUnit\Framework\TestCase;

/**
 * DeletedFiles owns three behaviors worth locking down with a real test,
 * not just a docblock: allSince() filters by `recordedAt` (this node's own
 * "when did I learn this"), never `deletedAt` (the original event time,
 * unchanged across relay hops) - the two are easy to accidentally swap
 * since they're both plain ints on the same entry; a 30-day prune that
 * runs on every write, not a background sweep; and backward-compatible
 * reads of a pre-existing bare-int entry (written before the
 * {deletedAt, recordedAt} shape existed).
 *
 * Runs against a REAL directory under the test app's own WRITEPATH, not a
 * vfsstream virtual filesystem - DeletedFiles::path() hardcodes WRITEPATH
 * internally (a framework constant, fixed at bootstrap, not injectable
 * without changing the class's own public contract), so redirecting it
 * onto a virtual filesystem isn't possible without a refactor this pass
 * didn't set out to make. A per-test-unique stateDir keeps this isolated
 * from real app state and from other tests instead.
 */
final class DeletedFilesTest extends TestCase
{
    private string $stateDir;
    private DeletedFiles $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateDir = 'Cluster-test-' . bin2hex(random_bytes(6));
        $config           = new ClusterConfig();
        $config->stateDir = $this->stateDir;
        $this->files       = new DeletedFiles($config);
    }

    protected function tearDown(): void
    {
        $path = $this->files->path();
        if (is_file($path)) {
            unlink($path);
        }
        $dir = dirname($path);
        if (is_dir($dir)) {
            @rmdir($dir);
        }

        parent::tearDown();
    }

    public function testRecordThenAllSinceReturnsTheDeletedAtValueNotRecordedAt(): void
    {
        $deletedAt = time() - 3600;
        $this->files->record('share/report.pdf', $deletedAt);

        $result = $this->files->allSince(time() - 7200);

        $this->assertArrayHasKey('share/report.pdf', $result);
        // The VALUE returned is deletedAt (a peer applying this needs the
        // original event time for its own LWW comparison), even though
        // the filter that selected it used recordedAt.
        $this->assertSame($deletedAt, $result['share/report.pdf']);
    }

    public function testAllSinceExcludesAnEntryRecordedBeforeTheCutoff(): void
    {
        $this->files->record('share/old.txt', time() - 100);

        // recordedAt is stamped as time() by record() itself, so asking
        // for "since right now" must exclude an entry that was just
        // recorded a moment ago, however old its deletedAt claims to be.
        $result = $this->files->allSince(time());

        $this->assertArrayNotHasKey('share/old.txt', $result);
    }

    public function testRecordIgnoresAnEmptyPath(): void
    {
        $this->files->record('', time());

        $this->assertFalse(is_file($this->files->path()));
    }

    public function testAllSinceOnAMissingFileReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->files->allSince(0));
    }

    public function testRecordPrunesTombstonesOlderThanThirtyDays(): void
    {
        // Written directly (not via record()) so both entries pre-date
        // this test - record()'s own prune sweep looks at the WRITTEN
        // file, not just what this call itself adds.
        $veryOld = time() - (31 * 86400);
        $recent  = time() - 86400;
        $path    = $this->files->path();
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode([
            'share/ancient.log'  => ['deletedAt' => $veryOld, 'recordedAt' => $veryOld],
            'share/yesterday.log' => ['deletedAt' => $recent, 'recordedAt' => $recent],
        ]));

        // Any write triggers the prune sweep - use a third, unrelated
        // delete to trigger it without disturbing the two fixture entries
        // above.
        $this->files->record('share/trigger.log', time());

        $onDisk = json_decode((string) file_get_contents($path), true);
        $this->assertArrayNotHasKey('share/ancient.log', $onDisk);
        $this->assertArrayHasKey('share/yesterday.log', $onDisk);
        $this->assertArrayHasKey('share/trigger.log', $onDisk);
    }

    public function testAllSinceNormalizesALegacyBareIntEntry(): void
    {
        // Written before {deletedAt, recordedAt} existed - a bare unix
        // timestamp as the value. normalizeEntry() must treat it as
        // recordedAt === deletedAt, matching that field's only previous
        // behavior for an entry like this.
        $legacyTimestamp = time() - 10;
        $path             = $this->files->path();
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode([
            'share/legacy.csv' => $legacyTimestamp,
        ]));

        $result = $this->files->allSince($legacyTimestamp - 60);

        $this->assertSame($legacyTimestamp, $result['share/legacy.csv'] ?? null);
    }
}
