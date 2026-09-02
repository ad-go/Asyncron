<?php

declare(strict_types=1);

namespace AdGo\Cluster;

use AdGo\Cluster\Config\Cluster as ClusterConfig;

/**
 * Since-filterable record of changes to THIS node's own cluster.nodes
 * registry (writable/Cluster/node_registry_state.json) - what lets
 * cluster.nodes propagate to every node automatically instead of the
 * manual export/import cycle this package relied on before (found live
 * 2026-08-21: adding a node only ever updated the ADDING node's own
 * .env, every other node needed a hand re-import to even learn the new
 * peer existed).
 *
 * Two independent halves in one file, same "why one file, not two"
 * reasoning as RemoteTestQueue's own docblock:
 * - "changes": an upsert (add, or an existing entry's baseURL/type/
 *   publicKey changed) THIS node knows about, keyed by node name.
 * - "deletions": a tombstone for a node THIS node has removed - same
 *   role DeletedFiles plays for file deletions, and for the identical
 *   reason: a removed entry is by definition no longer IN "changes",
 *   so there's nothing left there for a peer's `since` query to find.
 *
 * changesSince()/deletionsSince() filter by `recordedAt` (THIS node's
 * own "when did I learn this"), not the change's own origin time -
 * same split, same reasoning, as DbManifest's own docblock: a peer
 * relaying this through a third node needs "since I last checked THAT
 * node", not "since the change first happened anywhere in the mesh".
 *
 * Written by three callers, mirroring DeletedFiles' own "written by two
 * callers" shape:
 * - SettingsController::addNode()/deleteNode()/configureClusterIdentity()
 *   (a freshly-generated signing key changes THIS node's own publicKey)
 *   record the LOCAL change directly, the moment cluster.nodes itself
 *   is written.
 * - Cluster::applyIncomingNodeUpsert()/applyIncomingNodeDelete() record
 *   an entry on every OTHER node that applies an incoming change
 *   (received via push OR pull) - what keeps it propagating outward
 *   without any node needing to explicitly relay/forward anything,
 *   same full-mesh-emergent-property reasoning LongPollCommand's own
 *   docblock already gives for files/db-rows/invalidations.
 */
class NodeRegistryState
{
    private ClusterConfig $config;

    // Same 30-day retention as DeletedFiles - far longer than any
    // realistic pullLookbackSeconds/cron-downtime gap this exists to
    // cover, just keeps the file from growing forever on a cluster with
    // heavy node churn.
    private const MAX_AGE_SECONDS = 30 * 86400;

    public function __construct(?ClusterConfig $config = null)
    {
        $this->config = $config ?? config('Cluster');
    }

    public function path(): string
    {
        return rtrim(WRITEPATH, '/\\') . '/' . trim($this->config->stateDir, '/\\') . '/node_registry_state.json';
    }

    /**
     * @param array{baseURL: string, type: string, publicKey: string} $entry
     */
    public function recordChange(string $name, array $entry): void
    {
        if ($name === '') {
            return;
        }

        $this->mutate(static function (array $state) use ($name, $entry): array {
            $state['changes'][$name] = ['entry' => $entry, 'recordedAt' => time()];
            // A re-add cancels any earlier tombstone for the same name -
            // otherwise a peer that already applied the deletion (and so
            // stopped seeing this name in "changes") could see it appear
            // in "changes" while an OLDER "deletions" entry for it is
            // still also within their own since-window, a contradictory
            // pair with no defined resolution order.
            unset($state['deletions'][$name]);

            return $state;
        });
    }

    public function recordDeletion(string $name, int $deletedAt): void
    {
        if ($name === '') {
            return;
        }

        $this->mutate(static function (array $state) use ($name, $deletedAt): array {
            $state['deletions'][$name] = ['deletedAt' => $deletedAt, 'recordedAt' => time()];
            unset($state['changes'][$name]);

            return $state;
        });
    }

    /**
     * @return array<string, array{baseURL: string, type: string, publicKey: string}>
     */
    public function changesSince(int $since): array
    {
        $result = [];
        foreach ($this->read()['changes'] as $name => $change) {
            if ((int) ($change['recordedAt'] ?? 0) > $since) {
                $result[$name] = (array) ($change['entry'] ?? []);
            }
        }

        return $result;
    }

    /**
     * @return array<string, int> name => deletedAt
     */
    public function deletionsSince(int $since): array
    {
        $result = [];
        foreach ($this->read()['deletions'] as $name => $deletion) {
            if ((int) ($deletion['recordedAt'] ?? 0) > $since) {
                $result[$name] = (int) ($deletion['deletedAt'] ?? 0);
            }
        }

        return $result;
    }

    /**
     * @param callable(array{changes: array<string, array>, deletions: array<string, array>}): array $mutator
     */
    private function mutate(callable $mutator): void
    {
        $path = $this->path();
        $dir  = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return; // best-effort - must never break the actual registry write
        }

        $handle = fopen($path, 'cb+');
        if ($handle === false) {
            return;
        }

        flock($handle, LOCK_EX);
        $contents = stream_get_contents($handle);
        $state    = json_decode((string) $contents, true);
        $state    = is_array($state) ? $state : [];
        $state['changes']   ??= [];
        $state['deletions'] ??= [];

        $state = $mutator($state);
        $state = $this->prune($state);

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        @chmod($path, 0666);
    }

    /**
     * @return array{changes: array<string, array>, deletions: array<string, array>}
     */
    private function read(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return ['changes' => [], 'deletions' => []];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['changes' => [], 'deletions' => []];
        }
        flock($handle, LOCK_SH);
        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        $state = json_decode((string) $contents, true);
        $state = is_array($state) ? $state : [];
        $state['changes']   ??= [];
        $state['deletions'] ??= [];

        return $state;
    }

    /**
     * @param array{changes: array<string, array>, deletions: array<string, array>} $state
     *
     * @return array{changes: array<string, array>, deletions: array<string, array>}
     */
    private function prune(array $state): array
    {
        $cutoff = time() - self::MAX_AGE_SECONDS;

        $state['changes'] = array_filter(
            $state['changes'],
            static fn (array $c): bool => (int) ($c['recordedAt'] ?? 0) > $cutoff
        );
        $state['deletions'] = array_filter(
            $state['deletions'],
            static fn (array $d): bool => (int) ($d['recordedAt'] ?? 0) > $cutoff
        );

        return $state;
    }
}
