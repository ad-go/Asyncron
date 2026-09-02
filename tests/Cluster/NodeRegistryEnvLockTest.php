<?php

declare(strict_types=1);

namespace Tests\Cluster;

use AdGo\Cluster\Cluster;
use AdGo\Cluster\Config\Cluster as ClusterConfig;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * writeNodeRegistryEnv() replaces the cluster.nodes line by reading the
 * WHOLE .env file, regex-locating that one line, and writing the whole
 * file back - see that method's own docblock for why it can't just append.
 * Before it took a fopen()+flock(LOCK_EX) lock around that cycle, a bare
 * file_get_contents() read racing a concurrent writer that had already
 * ftruncate()'d the file (but not finished writing its replacement yet)
 * would read an EMPTY string back - and since an empty string never
 * matches the cluster.nodes regex, that falls into the "no match, append"
 * branch on an empty buffer, silently discarding every OTHER cluster.*
 * .env line (thisNode, secretToken, ...) already on disk. Exactly the bug
 * fixed for the analogous SettingsController::writeEnvLines() in cbff9bb
 * ("Fix .env writes losing peer keys under concurrent requests") - this
 * test proves the same fix, ported here, closes the same hole.
 *
 * Runs against the REAL ROOTPATH/.env, backed up and restored around the
 * test - writeNodeRegistryEnv() hardcodes ROOTPATH internally (same
 * "framework constant, fixed at bootstrap, not injectable" constraint
 * DeletedFilesTest's own docblock explains for WRITEPATH), so redirecting
 * it onto a throwaway path isn't possible without a refactor this test
 * isn't making.
 */
final class NodeRegistryEnvLockTest extends TestCase
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

    public function testWriteWaitsForAConcurrentLockHolderInsteadOfReadingATornFile(): void
    {
        file_put_contents(
            $this->envPath,
            "cluster.thisNode = \"self\"\n"
            . "cluster.secretToken = \"topsecret\"\n"
            . "cluster.nodes = \"nodeA|http://a/|public\"\n"
        );

        // A standalone script (no autoload/ROOTPATH needed - plain file
        // I/O) that: takes the lock, truncates the file (the vulnerable
        // in-progress state a bare file_get_contents() could read mid-
        // write), signals readiness over STDOUT so the assertion below
        // never races the subprocess's own startup time, then after a
        // deliberate delay writes back the original content plus one
        // more node - simulating a real second writer's completed,
        // legitimate concurrent update.
        $holderScript = tempnam(sys_get_temp_dir(), 'asyncron-holder-') . '.php';
        file_put_contents($holderScript, <<<'PHP'
            <?php
            [$path, $sleepMs] = [$argv[1], (int) $argv[2]];
            $h = fopen($path, 'r+');
            flock($h, LOCK_EX);
            $content = stream_get_contents($h);
            rewind($h);
            ftruncate($h, 0);
            fflush($h);
            fwrite(STDOUT, "LOCKED\n");
            fflush(STDOUT);
            usleep($sleepMs * 1000);
            $new = str_replace(
                'nodeA|http://a/|public"',
                'nodeA|http://a/|public,nodeB|http://b/|public"',
                $content
            );
            fwrite($h, $new);
            fflush($h);
            flock($h, LOCK_UN);
            fclose($h);
            PHP);

        $sleepMs = 400;
        $process = proc_open(
            [PHP_BINARY, $holderScript, $this->envPath, (string) $sleepMs],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertNotFalse($process, 'failed to spawn the lock-holder subprocess');

        // Blocks until the holder confirms it already holds the lock AND
        // has truncated the file - deterministic, unlike a fixed sleep
        // racing the subprocess's own startup time.
        $this->assertSame('LOCKED', trim((string) fgets($pipes[1])));

        $config           = new ClusterConfig();
        $config->thisNode = 'self';
        $cluster          = new Cluster($config);

        $method = new ReflectionMethod(Cluster::class, 'writeNodeRegistryEnv');
        $method->setAccessible(true);

        $start = microtime(true);
        $ok    = $method->invoke($cluster, [
            'nodeC' => ['baseURL' => 'http://c/', 'type' => 'public', 'publicKey' => ''],
        ]);
        $elapsed = microtime(true) - $start;

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($holderScript);

        $this->assertTrue($ok);
        // Proves the call genuinely blocked on the holder's lock rather
        // than racing past it - the pre-fix bare file_get_contents() call
        // returns almost instantly, well under the holder's hold time.
        $this->assertGreaterThanOrEqual(($sleepMs - 50) / 1000, $elapsed);

        $final = file_get_contents($this->envPath);

        // The two lines a torn read would have silently dropped...
        $this->assertStringContainsString('cluster.thisNode = "self"', $final);
        $this->assertStringContainsString('cluster.secretToken = "topsecret"', $final);
        // ...and this call's own change still landed.
        $this->assertStringContainsString('nodeC', $final);
    }
}
