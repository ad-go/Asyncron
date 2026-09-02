<?php

declare(strict_types=1);

namespace AdGo\Cluster;

use AdGo\Cluster\Config\Cluster as ClusterConfig;

/**
 * Latest connectivity check per node (writable/Cluster/
 * node_connections.json) - one entry per node name, OVERWRITTEN on every
 * fresh check (see NodeConnectivityChecker), not a growing log - same
 * "latest snapshot, not history" shape as TimeDrift, same flock-JSON
 * convention as every other state file here. Covers whichever transport
 * (SSH/SCP, FTP/FTPS, or the cluster/ping API fallback) each peer's own
 * `Nodes.protocol` selects, not SSH specifically - see
 * NodeConnectivityChecker's own docblock for why this was generalized.
 */
class NodeConnectivityLog
{
    private ClusterConfig $config;

    public function __construct(?ClusterConfig $config = null)
    {
        $this->config = $config ?? config('Cluster');
    }

    public function path(): string
    {
        return rtrim(WRITEPATH, '/\\') . '/' . trim($this->config->stateDir, '/\\') . '/node_connections.json';
    }

    /**
     * @param array{checkedAt: int, ok: bool, latencySeconds?: float, error?: string} $entry
     */
    public function record(string $nodeName, array $entry): void
    {
        $path = $this->path();
        $dir  = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return; // best-effort - a log write failing must never break the actual check/CLI output
        }

        $handle = fopen($path, 'cb+');
        if ($handle === false) {
            return;
        }

        flock($handle, LOCK_EX);
        $contents = stream_get_contents($handle);
        $entries  = json_decode((string) $contents, true);
        $entries  = is_array($entries) ? $entries : [];

        $entries[$nodeName] = $entry;

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        // See Cluster::saveManifest()'s own comment on this - setgid alone
        // doesn't make a freshly-created file group-writable, only
        // group-owned.
        @chmod($path, 0666);
    }

    /**
     * @return array<string, array{checkedAt: int, ok: bool, latencySeconds?: float, error?: string}>
     */
    public function all(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        flock($handle, LOCK_SH);
        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        $entries = json_decode((string) $contents, true);

        return is_array($entries) ? $entries : [];
    }
}
