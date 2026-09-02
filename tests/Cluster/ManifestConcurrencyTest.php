<?php

declare(strict_types=1);

namespace Tests\Cluster;

use AdGo\Cluster\Cluster;
use AdGo\Cluster\Config\Cluster as ClusterConfig;
use PHPUnit\Framework\TestCase;

/**
 * recordKnown() used to do loadManifest() (its own SH lock, released
 * immediately after) then saveManifest() (a SEPARATE EX lock, acquired
 * later) - each half individually flock()'d and safe on its own, but with
 * a real gap between them. A concurrent writer whose entire load-mutate-
 * save cycle lands inside that gap completes cleanly and safely too - and
 * then gets silently erased the moment the first caller's stale in-memory
 * snapshot (captured before the gap) gets written out via saveManifest().
 * Not a torn/corrupted file - a clean, valid, but WRONG one.
 *
 * testTheOldLoadThenSaveTwoStepLosesAConcurrentWritersEntry() reproduces
 * that exact mechanism by hand, deterministically - a real two-subprocess
 * race can't reliably land inside a gap that narrow (a handful of PHP
 * opcodes between one flock(LOCK_UN) and the next flock(LOCK_EX)), and a
 * lock-holding subprocess timed to run BEFORE the call under test (tried
 * first, empirically) only proves the call blocks on an already-held
 * lock, which the OLD loadManifest()'s own SH lock did too - it doesn't
 * exercise the gap this bug actually lived in.
 *
 * testRecordKnownBlocksForARealConcurrentLockHolder() then covers the
 * real, current recordKnown() under genuine subprocess concurrency - a
 * property both old and new code happen to share (so it doesn't
 * discriminate the fix on its own), but real coverage of the actual
 * production method is still worth having alongside the mechanism proof
 * above.
 *
 * manifestPath() is WRITEPATH + the configurable stateDir - a fresh
 * per-test stateDir keeps this fully isolated, same approach as
 * DbManifestTest/DeletedFilesTest.
 */
final class ManifestConcurrencyTest extends TestCase
{
    private Cluster $cluster;
    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $config             = new ClusterConfig();
        $config->stateDir   = 'Cluster-test-' . bin2hex(random_bytes(6));
        $this->cluster      = new Cluster($config);
        $this->manifestPath = $this->cluster->manifestPath();
    }

    protected function tearDown(): void
    {
        if (is_file($this->manifestPath)) {
            unlink($this->manifestPath);
        }
        $dir = dirname($this->manifestPath);
        if (is_dir($dir)) {
            @rmdir($dir);
        }

        parent::tearDown();
    }

    public function testTheOldLoadThenSaveTwoStepLosesAConcurrentWritersEntry(): void
    {
        // Step 1 of the old recordKnown(): load a snapshot, then (in the
        // real bug) do some work before saving - modeled here by simply
        // holding onto it while something else happens.
        $staleSnapshot = $this->cluster->loadManifest();

        // A concurrent writer's COMPLETE, independently-safe cycle lands
        // entirely inside the gap between that load and the save below -
        // this alone is fine; recordKnown() itself is correct in
        // isolation, same as it is today.
        $this->cluster->recordKnown('pathB', 'hashB', 222, 22);

        // Step 2 of the old recordKnown(): save the ALREADY-STALE
        // snapshot from step 1, which has no idea pathB now exists.
        $staleSnapshot['pathA'] = ['hash' => 'hashA', 'mtime' => 111, 'size' => 11, 'recordedAt' => time()];
        $this->cluster->saveManifest($staleSnapshot);

        $final = json_decode((string) file_get_contents($this->manifestPath), true);

        $this->assertArrayHasKey('pathA', $final);
        // The bug: pathB is gone - silently overwritten by a snapshot
        // taken before it ever existed, even though each individual
        // load/save call was itself perfectly lock-safe.
        $this->assertArrayNotHasKey('pathB', $final);
    }

    public function testRecordKnownBlocksForARealConcurrentLockHolder(): void
    {
        $dir = dirname($this->manifestPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Standalone script (no autoload needed - plain JSON file I/O
        // matching updateManifest()'s own on-disk protocol) that takes
        // the manifest lock first, signals readiness over STDOUT so the
        // assertion below never races the subprocess's own startup time,
        // then after a deliberate delay writes its own entry and
        // releases.
        $holderScript = tempnam(sys_get_temp_dir(), 'asyncron-manifest-holder-') . '.php';
        file_put_contents($holderScript, <<<'PHP'
            <?php
            [$path, $sleepMs] = [$argv[1], (int) $argv[2]];
            $h = fopen($path, 'c+b');
            flock($h, LOCK_EX);
            $manifest = json_decode((string) stream_get_contents($h), true) ?: [];
            fwrite(STDOUT, "LOCKED\n");
            fflush(STDOUT);
            usleep($sleepMs * 1000);
            $manifest['pathB'] = ['hash' => 'hashB', 'mtime' => 222, 'size' => 22, 'recordedAt' => time()];
            rewind($h);
            ftruncate($h, 0);
            fwrite($h, json_encode($manifest));
            fflush($h);
            flock($h, LOCK_UN);
            fclose($h);
            PHP);

        $sleepMs = 400;
        $process = proc_open(
            [PHP_BINARY, $holderScript, $this->manifestPath, (string) $sleepMs],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        $this->assertNotFalse($process, 'failed to spawn the lock-holder subprocess');

        // Blocks until the holder confirms it already holds the lock -
        // deterministic, unlike a fixed sleep racing the subprocess's own
        // startup time.
        $this->assertSame('LOCKED', trim((string) fgets($pipes[1])));

        $start = microtime(true);
        $this->cluster->recordKnown('pathA', 'hashA', 111, 11);
        $elapsed = microtime(true) - $start;

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($holderScript);

        // Proves the call genuinely blocked on the holder's lock rather
        // than racing past it and reading a stale/empty manifest.
        $this->assertGreaterThanOrEqual(($sleepMs - 50) / 1000, $elapsed);

        $final = json_decode((string) file_get_contents($this->manifestPath), true);

        $this->assertArrayHasKey('pathA', $final);
        $this->assertArrayHasKey('pathB', $final);
    }
}
