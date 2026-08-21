<?php

declare(strict_types=1);

namespace AdGo\Cluster\Commands;

use AdGo\Cluster\Cluster;
use AdGo\Cluster\Config\Cluster as ClusterConfig;
use AdGo\Cluster\PullSync;
use AdGo\Cluster\SyncState;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Long-poll counterpart to cluster:pull (still supported, unchanged - runs
 * alongside this, not replaced by it). Instead of one quick request/
 * response per channel every cron tick, opens ONE connection held
 * server-side for up to ~28s (see LongPollController::poll()'s own
 * docblock), checked every second, replying the instant something's ready
 * - cutting worst-case NAT-to-NAT relay latency from "up to a full pull
 * cadence" to "up to one second".
 *
 * Talks to exactly ONE public peer per round, not all of them - a single
 * connection is enough: SyncFilesCommand/SyncDbCommand already push every
 * change to EVERY configured public peer directly, from whichever node
 * originates it, NAT or public (Cluster::publicPeers() doesn't special-
 * case the caller's own type - see that method's own docblock). So any
 * ONE public peer already ends up with a complete picture of the whole
 * cluster within one push cycle, on its own, without this command needing
 * to ask all of them - full mesh is an emergent property of the existing
 * push design, not something built here. Verified live 2026-08-20 (see
 * this command's own git history) that querying every peer concurrently
 * found nothing a single peer wouldn't have.
 *
 * The peer used ROTATES every round (persisted cursor - writable/Cluster/
 * long_poll_rotation.json, same flock-JSON convention as every other
 * state file in this package), cycling through every configured public
 * peer in turn: momentary staleness on one peer (its own push-processing
 * cycle hasn't caught up yet, or one specific incoming push job is mid-
 * retry) self-heals on the NEXT round, which lands on a different peer -
 * same "eventually consistent, safe to re-ask" philosophy cluster:pull's
 * own rolling lookback window already relies on, just spread across peers
 * instead of across time.
 *
 * Runs the round repeatedly per invocation (back-to-back, no sleep
 * between - the server-side hold itself already paces it, and each round
 * advances the rotation), up to MAX_ROUNDS or MAX_TOTAL_SECONDS,
 * whichever comes first - see those constants' own docblocks for why
 * MAX_TOTAL_SECONDS is the one that actually bounds a quiet tick (each
 * idle round costs the server's full ~28s hold), while MAX_ROUNDS exists
 * so a BUSY tick - rounds returning fast because there's always
 * something ready to relay right away - can cycle through many more
 * public peers within the SAME cron-triggered tasks:run tick (the finest
 * granularity most hosting cron actually offers) instead of stopping
 * dead once a fixed round count was hit, with zero crontab changes
 * needed.
 *
 * The "one peer per round is enough" reasoning above does NOT hold for
 * test-requests specifically: unlike files/db-rows/invalidations (pushed
 * to EVERY public peer directly, so any one of them already has the full
 * picture), a RemoteTestQueue entry only ever exists on whichever ONE
 * public peer the admin happened to be browsing when they clicked "test" -
 * found live 2026-08-20, testing bak's Databases row from h1q's Settings
 * page took anywhere from 5s to 47s depending on whether the rotation
 * happened to be holding a connection to h1q right then, not the sub-30s
 * this class's own docblock promises. quickTestRequestSweep() below closes
 * that gap: a fast, non-holding pull-test-requests-only check against
 * every OTHER public peer too - cheap (a plain GET, near-instant when
 * nothing's queued) and independent of which peer this invocation's held
 * rounds happen to land on. Run before EACH round (not just once at the
 * start of the invocation), so a request queued between two rounds still
 * gets caught before the NEXT round's own ~28s hold finishes, rather than
 * waiting for the next cron tick entirely - worst case drops from "up to
 * a full tick" to "up to one round's hold, ~28-30s", bounded by however
 * many rounds this invocation actually runs (see MAX_ROUNDS/
 * MAX_TOTAL_SECONDS), not a continuous interval.
 *
 * Scheduled via app/Config/Tasks.php, same as cluster:pull:
 *   $schedule->command('cluster:long-poll')->everyMinute();
 */
class LongPollCommand extends BaseCommand
{
    protected $group = 'Cluster';

    protected $name = 'cluster:long-poll';

    protected $description = 'Long-poll one rotating public peer at a time for near-real-time pull.';

    // Must stay comfortably above the server's own hold (28s - see
    // LongPollController::HOLD_SECONDS) so a slow-but-legitimate response
    // isn't cut off client-side right as the server was about to answer.
    private const CLIENT_TIMEOUT_SECONDS = 40;

    // A hard ceiling on the ROUND loop (timed from right after
    // quickTestRequestSweep(), not from run()'s own start) so a slow tick
    // can never overlap into territory the NEXT cron-triggered tick would
    // also be running in. The sweep itself adds up to
    // SWEEP_TIMEOUT_SECONDS * (peer count - 1) on top in the worst case (a
    // genuinely unreachable peer, not the normal near-instant case) -
    // acceptable since a run that overlaps the next tick just makes that
    // next tick skip via acquireLock(), the same self-healing behavior
    // cluster:pull already relies on. This, not MAX_ROUNDS below, is what
    // actually bounds an idle tick - each idle round costs the server's
    // full ~28s hold, so two of them already nearly fill this budget.
    private const MAX_TOTAL_SECONDS = 55;

    // Not the practical limiter on a quiet tick (MAX_TOTAL_SECONDS is,
    // per above) - this exists so a BUSY tick, where round() keeps
    // returning fast because there's always something ready to relay
    // immediately (see LongPollController::poll()'s own "reply the
    // instant something's ready"), can keep rotating through public
    // peers instead of stopping dead at a hard "2" once real work
    // stopped being the bottleneck. Found live 2026-08-21: with the old
    // hard cap of 2, a NAT node whose rotation hadn't yet reached the
    // one public peer holding a given change had to wait for the NEXT
    // cron-triggered tick entirely (res: 81s to receive one file, vs.
    // bak's 25s the same round because its rotation happened to land on
    // the right peer sooner) - raising this lets extra, cheap, fast
    // rounds absorb that slack within the SAME tick instead.
    private const MAX_ROUNDS = 12;

    public function run(array $params)
    {
        $config = config('Cluster');
        if (! $config->fileSyncEnabled && ! $config->sessionSyncEnabled) {
            CLI::write('cluster:long-poll: fileSyncEnabled and sessionSyncEnabled are both false, skipping.', 'yellow');

            return;
        }

        $cluster   = new Cluster();
        $peerNames = array_keys($cluster->publicPeers());

        if ($peerNames === []) {
            CLI::write('cluster:long-poll: no public peers configured, nothing to do.', 'yellow');

            return;
        }

        // Same non-blocking overlap guard as cluster:pull, but its OWN
        // separate lock file - unlike two copies of the SAME command,
        // cluster:pull and cluster:long-poll applying the same content
        // concurrently is completely safe (every apply*() method is
        // idempotent - a repeat is a cheap no-op, never a duplicate), so
        // there's no correctness reason to make them block each other;
        // sharing cluster:pull's own lock would just mean one transport
        // starves the other for no benefit.
        $lock = $this->acquireLock();
        if ($lock === null) {
            CLI::write('cluster:long-poll: another long-poll run is still in progress, skipping.', 'yellow');

            return;
        }

        try {
            $start  = microtime(true);
            $rounds = 0;
            do {
                // Before EACH round, not just once at the start - a
                // request queued right after the first sweep still has to
                // wait for the SECOND one (~HOLD_SECONDS later, once
                // round() below returns) rather than for the next cron
                // tick entirely. See this class's own docblock.
                $this->quickTestRequestSweep($cluster, $peerNames);

                $peerName = $this->nextPeer($peerNames);
                $this->round($config, $cluster, $peerName, $cluster->publicPeers()[$peerName]);
                $rounds++;
            } while ($rounds < self::MAX_ROUNDS && (microtime(true) - $start) < self::MAX_TOTAL_SECONDS);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function round(ClusterConfig $config, Cluster $cluster, string $peerName, array $node): void
    {
        $since = time() - max(1, $config->pullLookbackSeconds);
        $sync  = new PullSync();

        $invalidationsApplied = 0;
        $filesDownloaded      = 0;
        $filesDeleted         = 0;
        $dbRowsApplied        = 0;
        $testsRelayed         = 0;
        $registryApplied      = 0;
        $errors               = 0;

        try {
            $client   = $cluster->peerClient($node['baseURL'], self::CLIENT_TIMEOUT_SECONDS);
            $response = $client->post('cluster/long-poll', [
                'headers' => ['Authorization' => $cluster->authHeader()],
                'form_params' => ['since' => $since],
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('long-poll HTTP ' . $response->getStatusCode());
            }

            $decoded = json_decode($response->getBody(), true);
            if (! is_array($decoded)) {
                throw new \RuntimeException('long-poll returned invalid JSON');
            }

            if ($config->sessionSyncEnabled) {
                $invalidationsApplied += $sync->applyInvalidations(is_array($decoded['invalidations'] ?? null) ? $decoded['invalidations'] : []);
            }
            if ($config->fileSyncEnabled) {
                $driftOffset = 0.0;
                try {
                    $driftOffset = $cluster->measureDrift($client)['offset'];
                } catch (Throwable $e) {
                    // Intentionally swallowed - same reasoning as
                    // PullCommand::pass()'s own identical catch.
                }

                $remoteFiles = is_array($decoded['files'] ?? null) ? $decoded['files'] : [];
                $filesDownloaded += $sync->applyFilesManifest($client, $cluster, $remoteFiles, $peerName, $driftOffset)['downloaded'];
                $filesDeleted    += $sync->applyDeletedFiles($cluster, is_array($decoded['deletions'] ?? null) ? $decoded['deletions'] : []);
                $dbRowsApplied   += $sync->applyDbRows(is_array($decoded['dbRows'] ?? null) ? $decoded['dbRows'] : [], $peerName);
            }

            // No fileSyncEnabled/sessionSyncEnabled gate - same reasoning
            // as Cluster::broadcastNodeUpsert()'s own docblock.
            $registryApplied = $sync->applyNodeRegistrySnapshot(
                $cluster,
                is_array($decoded['registryChanges'] ?? null) ? $decoded['registryChanges'] : [],
                is_array($decoded['registryDeletions'] ?? null) ? $decoded['registryDeletions'] : []
            );

            (new SyncState($config))->recordPull($peerName, true);
        } catch (Throwable $e) {
            $errors++;
            CLI::write("cluster:long-poll: $peerName failed - " . $e->getMessage(), 'red');
            (new SyncState($config))->recordPull($peerName, false);

            if ($invalidationsApplied > 0 || $filesDownloaded > 0 || $filesDeleted > 0 || $dbRowsApplied > 0 || $errors > 0) {
                CLI::write(
                    sprintf('cluster:long-poll: %s - %d invalidation(s), %d file(s), %d deletion(s), %d db row(s), %d error(s).', $peerName, $invalidationsApplied, $filesDownloaded, $filesDeleted, $dbRowsApplied, $errors),
                    'yellow'
                );
            }

            return;
        }

        // Own try/catch, deliberately separate - same reasoning as
        // PullCommand::pass()'s own identical split.
        try {
            $testsRelayed += $sync->applyTestRequests($client, $cluster, is_array($decoded['testRequests'] ?? null) ? $decoded['testRequests'] : []);
        } catch (Throwable $e) {
            CLI::write("cluster:long-poll: $peerName test-relay failed - " . $e->getMessage(), 'yellow');
        }

        if ($invalidationsApplied > 0 || $filesDownloaded > 0 || $filesDeleted > 0 || $dbRowsApplied > 0 || $testsRelayed > 0 || $registryApplied > 0) {
            CLI::write(
                sprintf('cluster:long-poll: %s - %d invalidation(s), %d file(s), %d deletion(s), %d db row(s), %d test(s) relayed, %d registry change(s).', $peerName, $invalidationsApplied, $filesDownloaded, $filesDeleted, $dbRowsApplied, $testsRelayed, $registryApplied),
                'green'
            );
        }
    }

    // A plain GET per peer (PullSync::pullTestRequests(), the same call
    // PullCommand already makes every peer every minute) - not the 28s
    // held connection round() opens, so this adds negligible time even
    // against all of them. See this class's own docblock for why
    // test-requests specifically need this on top of the rotation.
    private const SWEEP_TIMEOUT_SECONDS = 8;

    /**
     * @param list<string> $peerNames
     */
    private function quickTestRequestSweep(Cluster $cluster, array $peerNames): void
    {
        $sync  = new PullSync();
        $peers = $cluster->publicPeers();

        foreach ($peerNames as $peerName) {
            $node = $peers[$peerName] ?? null;
            if ($node === null) {
                continue;
            }

            try {
                $client   = $cluster->peerClient($node['baseURL'], self::SWEEP_TIMEOUT_SECONDS);
                $relayed  = $sync->pullTestRequests($client, $cluster);
                if ($relayed > 0) {
                    CLI::write("cluster:long-poll: sweep $peerName - $relayed test(s) relayed.", 'green');
                }
            } catch (Throwable $e) {
                CLI::write("cluster:long-poll: sweep $peerName failed - " . $e->getMessage(), 'yellow');
            }
        }
    }

    /**
     * Advances and persists the rotation cursor, returning the peer name
     * to use THIS round. A peer removed from the registry since the last
     * run (cursor pointing past the end, or at a now-stale index) wraps
     * back to 0 rather than erroring.
     *
     * @param list<string> $peerNames
     */
    private function nextPeer(array $peerNames): string
    {
        $path = $this->rotationPath();
        $dir  = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = @fopen($path, 'cb+');
        if ($handle === false) {
            // Best-effort - no persisted cursor available, always start
            // from the first configured peer rather than failing the run.
            return $peerNames[0];
        }

        flock($handle, LOCK_EX);
        $contents = stream_get_contents($handle);
        $state    = json_decode((string) $contents, true);
        $index    = is_array($state) ? (int) ($state['index'] ?? 0) : 0;

        $index    = ($index + 1) % count($peerNames);
        $peerName = $peerNames[$index];

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode(['index' => $index, 'peer' => $peerName, 'at' => time()]));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        @chmod($path, 0666);

        return $peerName;
    }

    private function rotationPath(): string
    {
        return rtrim(WRITEPATH, '/\\') . '/' . trim(config('Cluster')->stateDir, '/\\') . '/long_poll_rotation.json';
    }

    /**
     * @return resource|null
     */
    private function acquireLock()
    {
        // Own file, deliberately distinct from Cluster::lockPath() (which
        // cluster:pull uses) - see run()'s own comment for why they must
        // NOT share one.
        $path = rtrim(WRITEPATH, '/\\') . '/' . trim(config('Cluster')->stateDir, '/\\') . '/long_poll.lock';
        $dir  = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        $handle = fopen($path, 'cb');
        if ($handle === false) {
            return null;
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }
        @chmod($path, 0666);

        return $handle;
    }
}
