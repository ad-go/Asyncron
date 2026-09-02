<?php

declare(strict_types=1);

namespace AdGo\Cluster;

use AdGo\Cluster\Config\Cluster as ClusterConfig;
use CodeIgniter\Settings\Settings;

/**
 * Standing connectivity check against EVERY configured peer, whatever
 * transport it actually has credentials for - SSH/SCP, FTP/FTPS, or (a
 * node with neither configured) the cluster/ping API fallback - all via
 * NodeConnectionChecker::checkNode(), the same connect-and-prove-usable
 * logic the Settings page's own on-demand "Test" button already uses.
 *
 * Originally SSH-only (this class was named SshChecker, doing its own raw
 * phpseclib login and SILENTLY SKIPPING any node with no `Nodes.sshHost`
 * configured - beta, an FTP/FTPS-only node, never got a standing
 * connectivity record at all). Generalized 2026-09-01: every peer now
 * gets checked through whichever transport its own `Nodes.protocol`
 * selects, so an FTP-only or API-only node gets the same kind of standing
 * health record an SSH one always has - "not configured" is no longer
 * indistinguishable from "never checked".
 *
 * Triggered two ways, both calling checkAll() - see Jobs\
 * NodeConnectivityCheckJob (queued right after a login - see README "How
 * it works", SSH connectivity checks & clock drift) and Commands\
 * NodeCheckCommand (scheduled every minute).
 */
class NodeConnectivityChecker
{
    private ClusterConfig $config;

    private NodeConnectivityLog $log;

    private NodeConnectionChecker $checker;

    public function __construct(?ClusterConfig $config = null, ?NodeConnectivityLog $log = null, ?NodeConnectionChecker $checker = null)
    {
        $this->config  = $config ?? config('Cluster');
        $this->log     = $log ?? new NodeConnectivityLog($this->config);
        $this->checker = $checker ?? new NodeConnectionChecker();
    }

    /**
     * @return array<string, array{checkedAt: int, ok: bool, latencySeconds?: float, protocol?: string, error?: string}>|null
     *               keyed by node name - EVERY configured peer is present,
     *               regardless of which transport (or none) it has
     *               credentials for. NULL (not an empty array - that
     *               already means "no peers configured at all", a real,
     *               different outcome NodeCheckCommand reports on) when
     *               another checkAll() is already in progress - see
     *               acquireLock().
     */
    public function checkAll(): ?array
    {
        // Same flock() convention as PullCommand::acquireLock() - without
        // it, a login-triggered NodeConnectivityCheckJob and the scheduled
        // `cluster:node-check` tick can run concurrently against the same
        // peers - two connection attempts to the same peer at once for
        // zero extra information.
        $lock = $this->acquireLock();
        if ($lock === null) {
            return null;
        }

        try {
            $cluster  = new Cluster($this->config);
            $settings = service('settings');

            $results = [];
            // Every configured peer regardless of type ('public' or 'nat') -
            // same reasoning as Cluster::measureDrift()'s own callers: two
            // 'nat' peers on the same private LAN can often reach each
            // other even though neither can be reached from the public
            // internet, so filtering by type here would silently miss
            // real, useful results.
            foreach ($cluster->allPeers() as $name => $node) {
                $results[$name] = $this->checkNode($name, $settings);
            }

            return $results;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return resource|null
     */
    private function acquireLock()
    {
        $path = rtrim(WRITEPATH, '/\\') . '/' . trim($this->config->stateDir, '/\\') . '/node-check.lock';
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
        // Same cross-user-write reasoning as Cluster::saveManifest()'s own
        // comment - this lock file's first creation comes from whichever
        // system user's cron/CLI happens to hit it first, and was
        // previously left at whatever restrictive default fopen()'s own
        // umask produced (never explicitly chmod'd at all, unlike every
        // other lock/state file in this package) - the one gap that
        // reasoning missed.
        @chmod($path, 0666);

        return $handle;
    }

    /**
     * @return array{checkedAt: int, ok: bool, latencySeconds?: float, protocol?: string, error?: string}
     */
    private function checkNode(string $name, Settings $settings): array
    {
        $start  = microtime(true);
        $result = $this->checker->checkNode($name, $settings);

        $entry = [
            'checkedAt'      => time(),
            'ok'             => $result['ok'],
            'latencySeconds' => round(($result['ms'] ?? (microtime(true) - $start) * 1000) / 1000, 3),
        ];
        if (isset($result['protocol'])) {
            $entry['protocol'] = $result['protocol'];
        }
        if (! $result['ok']) {
            $entry['error'] = $result['error'] ?? 'unknown error';
        }

        $this->log->record($name, $entry);

        return $entry;
    }
}
