<?php

declare(strict_types=1);

namespace AdGo\Cluster\Commands;

use AdGo\Cluster\Cluster;
use AdGo\Cluster\Config\Cluster as ClusterConfig;
use AdGo\Cluster\DbManifest;
use AdGo\Cluster\DbSyncSchema;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\ConnectionInterface;
use Throwable;

/**
 * Detects local DB changes (accounts/identities/groups/permissions/
 * profile, settings - see DbSyncSchema's own docblock for exactly what's
 * included and why) and queues delivery to every peer. Same scan-and-diff
 * detection model as cluster:sync-files, over DB rows instead of a
 * filesystem directory - Shield's models are vendor code with no generic
 * "any row changed" event to hook, so this polls instead (scheduled every
 * minute, same cadence as the other four commands).
 *
 * Delivery is one Queue job per changed entity per PUBLIC peer
 * ('cluster-sync-db-row', see Jobs\SyncDbRowJob) - 'nat' peers catch up
 * via the pull counterpart instead (see PullCommand::pullDbRows()), same
 * push/pull split every other concern in this package already uses.
 *
 * This command only EXPORTS (local DB -> queued commands). Applying an
 * INCOMING command (from a push or a pull) happens synchronously at the
 * receiving end (Controllers\DbSyncController::receive() /
 * PullCommand::pullDbRows()), via the shared DbSyncSchema::
 * applyIncomingCommand() - there is no separate "import" step here.
 */
class SyncDbCommand extends BaseCommand
{
    protected $group = 'Cluster';

    protected $name = 'cluster:sync-db';

    protected $description = 'Detect local DB changes and queue delivery to every peer.';

    public function run(array $params)
    {
        $config   = config('Cluster');
        $cluster  = new Cluster($config);
        $manifest = new DbManifest($config);
        $db       = db_connect('default');
        $peers    = $cluster->publicPeers();

        if ($peers === []) {
            CLI::write('cluster:sync-db: no public peers configured, nothing to do.', 'yellow');

            return;
        }

        $changed = 0;

        foreach (DbSyncSchema::exportAllUserEmails($db) as $email) {
            $snapshot = DbSyncSchema::exportUser($db, $email);
            if ($snapshot === null) {
                continue;
            }
            $hash = DbSyncSchema::hashUserSnapshot($snapshot);
            $key  = 'users:' . $email;
            $known = $manifest->get($key);
            if ($known !== null && $known['hash'] === $hash) {
                continue;
            }

            $timestamp = $this->rowTimestamp($snapshot['users']['updated_at'] ?? null);
            $manifest->record($key, ['hash' => $hash, 'timestamp' => $timestamp]);
            $this->enqueueToEveryPeer($config, $peers, 'users', $email, $snapshot, $timestamp);
            $changed++;
        }

        // Gated on the same per-node toggle applyIncomingCommand() checks
        // for the receiving side - see DbSyncSchema::settingsSyncEnabled()'s
        // own docblock. Skips the WHOLE loop, not just the enqueue call,
        // so a disabled node doesn't even pay for the export/hash/manifest
        // work on every settings row every minute while turned off.
        if (DbSyncSchema::settingsSyncEnabled()) {
            foreach (DbSyncSchema::exportAllSettingIds($db) as $id) {
                $snapshot = DbSyncSchema::exportSetting($db, (string) $id['class'], (string) $id['key'], (string) $id['context']);
                if ($snapshot === null) {
                    continue;
                }
                $hash = DbSyncSchema::hashSettingSnapshot($snapshot);
                // Bare "class:key:context" - NOT prefixed with the table name.
                // enqueueToEveryPeer() below sends this as the wire naturalKey,
                // and the receiving end's applyIncomingCommand() rebuilds its
                // OWN manifest key as "$table:$naturalKey" - passing the
                // already-prefixed $manifestKey here (found live 2026-08-21)
                // double-prefixed it on arrival ("settings:settings:..."),
                // permanently desyncing sender/receiver manifest keys for the
                // same entity and causing every receiving node to treat what
                // it just received as a brand-new local change on its own next
                // scan, forever re-broadcasting it cluster-wide. $key (below)
                // stays prefixed - that one's only ever used for THIS node's
                // own manifest lookups, same as the users loop above.
                $naturalKey = $id['class'] . ':' . $id['key'] . ':' . $id['context'];
                $key        = 'settings:' . $naturalKey;
                $known = $manifest->get($key);
                if ($known !== null && $known['hash'] === $hash) {
                    continue;
                }

                $timestamp = $this->rowTimestamp($snapshot['updated_at'] ?? null);
                $manifest->record($key, ['hash' => $hash, 'timestamp' => $timestamp]);
                $this->enqueueToEveryPeer($config, $peers, 'settings', $naturalKey, $snapshot, $timestamp);
                $changed++;
            }
        }

        // Config\Cluster::$dbSyncGroup - every table DbSyncSchema::
        // genericTables() auto-discovered in that whole connection group
        // (minus $dbExcludeTables and whatever's hardcoded elsewhere in
        // DbSyncSchema), same scan-and-diff detection and LWW timestamping
        // as settings above, just genuinely generic - see
        // DbSyncSchema::genericTables()/applyGenericSnapshot()'s own
        // docblocks. Gated on productionSyncEnabled() the same way settings
        // above is gated on settingsSyncEnabled() - skips the whole loop,
        // not just the enqueue, for the same "don't pay for the scan while
        // turned off" reasoning.
        if (DbSyncSchema::productionSyncEnabled()) {
            $changed += $this->scanAndEnqueueGenericTables($config, $peers, $manifest, $db, DbSyncSchema::genericTables());

            // genericIdBasedTables() (autoincrement-keyed or no-updated_at
            // tables) can only ever be pushed FROM the designated Source
            // node - see DbSyncSchema::genericIdBasedTables()' own
            // docblock for why an autoincrement id is only a safe
            // correlation key one-directionally. A non-source node still
            // RECEIVES these fine (DbSyncSchema::applyIncomingCommand()
            // applies them the same as any other generic table) - it just
            // never scans/pushes its own copy of them back out, so there's
            // no reverse flow for a mirror's own local drift to overwrite
            // the source with.
            if (DbSyncSchema::productionSourceNodeEnabled()) {
                $changed += $this->scanAndEnqueueGenericTables($config, $peers, $manifest, $db, DbSyncSchema::genericIdBasedTables());

                // genericCompositeKeyTables() (multi-column primary key
                // tables) - same one-directional Source-node-only reasoning
                // as genericIdBasedTables() just above: a composite key is
                // still an internal correlation key, not a natural key two
                // independent mirrors could safely merge against each
                // other, so only the Source node's own copy is ever
                // scanned/pushed out here.
                $changed += $this->scanAndEnqueueCompositeTables($config, $peers, $manifest, $db, DbSyncSchema::genericCompositeKeyTables());
            }
        }

        CLI::write("cluster:sync-db: $changed entit" . ($changed === 1 ? 'y' : 'ies') . ' changed, queued to ' . count($peers) . ' peer(s).', $changed > 0 ? 'green' : 'yellow');

        if (array_key_exists('bootstrap', $params) || CLI::getOption('bootstrap')) {
            $this->bootstrap($cluster, $peers, $db);
        }
    }

    /**
     * One-time safety valve for a Source node's FIRST ever scan of
     * genericIdBasedTables() after data that already matches every peer
     * (Commands\ImportProductionCommand's own clone) has landed there some
     * OTHER way - records this node's own manifest entry for every row
     * without enqueueing a single push. Found necessary live 2026-09-04:
     * without this, the very next scheduled cluster:sync-db tick would see
     * a completely empty manifest for these tables and treat every one of
     * ~97,000 already-identical rows as "new", enqueueing that many
     * individual push jobs in one run - not incorrect (the receiving
     * side's own content-hash check would just no-op each one), but the
     * scan/enqueue volume alone is close to the same class of overload
     * that took a node down earlier this project. Safe to run even when
     * the data ISN'T already identical everywhere - it just means this
     * node's manifest now reflects "as of right now", and anything a peer
     * already had that genuinely differs still gets a real, normal push
     * on whichever LATER tick first notices it changed again.
     *
     * Web trigger: RouteRegistrar's own fix-prime-production-manifest
     * route - which calls primeManifestChunk() below in a time-boxed
     * loop instead of this all-at-once version, since h1q's own php-fpm
     * pool enforces a hard ~30s request_terminate_timeout that NEITHER
     * set_time_limit(0) NOR ignore_user_abort(true) can override (that
     * one kills the WORKER PROCESS externally, not the script itself) -
     * found live 2026-09-04, this method alone never got to finish a
     * ~97,000-row scan there. CLI callers (`php spark cluster:sync-db`
     * has no such external limit) can still use this one-shot version.
     */
    public function primeManifest(): int
    {
        $config   = config('Cluster');
        $manifest = new DbManifest($config);
        $db       = db_connect('default');

        $primed = $this->scanAndEnqueueGenericTables($config, [], $manifest, $db, DbSyncSchema::genericIdBasedTables(), true);

        foreach (DbSyncSchema::genericCompositeKeyTables() as $table => $keyColumns) {
            foreach (DbSyncSchema::exportAllCompositeKeys($db, $table, $keyColumns) as $keyValue) {
                $snapshot = DbSyncSchema::exportCompositeRow($db, $table, $keyColumns, $keyValue);
                if ($snapshot === null) {
                    continue;
                }
                $hash      = DbSyncSchema::hashSettingSnapshot($snapshot);
                $timestamp = $this->rowTimestamp($snapshot['updated_at'] ?? null);
                $manifest->record("$table:$keyValue", ['hash' => $hash, 'timestamp' => $timestamp]);
                $primed++;
            }
        }

        return $primed;
    }

    /**
     * Chunked counterpart to primeManifest() above, for a host that kills
     * long-running requests externally - see that method's own docblock.
     * Re-fetches the table's own full key list on every call (a single
     * cheap SELECT of just the key column, not full rows) and slices it
     * by $offset/$limit; the caller loops this across tables and offsets
     * until every 'nextOffset' comes back null.
     *
     * Covers BOTH genericIdBasedTables() (single-column key) and
     * genericCompositeKeyTables() (multi-column key) - same source-only,
     * potentially-huge-first-scan risk either way, so the same chunking
     * safety valve applies to both.
     *
     * @return array{table: string, primed: int, total: int, nextOffset: int|null}|array{error: string}
     */
    public function primeManifestChunk(string $table, int $offset, int $limit): array
    {
        $byId      = DbSyncSchema::genericIdBasedTables();
        $composite = DbSyncSchema::genericCompositeKeyTables();
        if (! array_key_exists($table, $byId) && ! array_key_exists($table, $composite)) {
            return ['error' => "'$table' is not a source-only (genericIdBasedTables/genericCompositeKeyTables) table."];
        }
        $isComposite = array_key_exists($table, $composite);

        $config   = config('Cluster');
        $manifest = new DbManifest($config);
        $db       = db_connect('default');

        if ($isComposite) {
            $keyColumns = $composite[$table];
            $allKeys    = DbSyncSchema::exportAllCompositeKeys($db, $table, $keyColumns);
        } else {
            $keyColumn = $byId[$table];
            $allKeys   = DbSyncSchema::exportAllGenericKeys($db, $table, $keyColumn);
        }
        $slice = array_slice($allKeys, $offset, $limit);

        $primed = 0;
        foreach ($slice as $keyValue) {
            $snapshot = $isComposite
                ? DbSyncSchema::exportCompositeRow($db, $table, $keyColumns, $keyValue)
                : DbSyncSchema::exportGenericRow($db, $table, $keyColumn, $keyValue);
            if ($snapshot === null) {
                continue;
            }
            $hash      = DbSyncSchema::hashSettingSnapshot($snapshot);
            $timestamp = $this->rowTimestamp($snapshot['updated_at'] ?? null);
            $manifest->record("$table:$keyValue", ['hash' => $hash, 'timestamp' => $timestamp]);
            $primed++;
        }

        $nextOffset = ($offset + $limit) < count($allKeys) ? $offset + $limit : null;

        return ['table' => $table, 'primed' => $primed, 'total' => count($allKeys), 'nextOffset' => $nextOffset];
    }

    /**
     * Shared scan-and-diff body for BOTH DbSyncSchema::genericTables() and
     * ::genericIdBasedTables() - identical export/hash/manifest/enqueue
     * logic either way, only WHICH table map (and, at the call site
     * above, an extra productionSourceNodeEnabled() gate) differs between
     * them.
     *
     * @param array<string, array{baseURL: string, type: string}> $peers
     * @param array<string, string>                                $tables    table => primary-key column
     * @param bool                                                 $primeOnly records the manifest entry for
     *                                                                        every row but never enqueues -
     *                                                                        see primeManifest()'s own docblock
     *                                                                        for why this exists
     */
    private function scanAndEnqueueGenericTables(ClusterConfig $config, array $peers, DbManifest $manifest, ConnectionInterface $db, array $tables, bool $primeOnly = false): int
    {
        $changed = 0;

        foreach ($tables as $table => $keyColumn) {
            foreach (DbSyncSchema::exportAllGenericKeys($db, $table, $keyColumn) as $keyValue) {
                $snapshot = DbSyncSchema::exportGenericRow($db, $table, $keyColumn, $keyValue);
                if ($snapshot === null) {
                    continue;
                }
                $hash  = DbSyncSchema::hashSettingSnapshot($snapshot);
                $key   = $keyValue;
                $known = $manifest->get("$table:$key");
                if ($known !== null && $known['hash'] === $hash) {
                    continue;
                }

                $timestamp = $this->rowTimestamp($snapshot['updated_at'] ?? null);
                $manifest->record("$table:$key", ['hash' => $hash, 'timestamp' => $timestamp]);
                if (! $primeOnly) {
                    $this->enqueueToEveryPeer($config, $peers, $table, $key, $snapshot, $timestamp);
                }
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Composite-primary-key counterpart to scanAndEnqueueGenericTables()
     * above - identical scan/diff/enqueue shape, just against
     * DbSyncSchema::exportAllCompositeKeys()/exportCompositeRow() (a
     * $keyColumns array per table instead of one $keyColumn string), for
     * DbSyncSchema::genericCompositeKeyTables()'s own multi-column-PK
     * tables. Kept as its own method rather than folded into the
     * single-column one above since the two export/hash calls take a
     * genuinely different shape of key argument.
     *
     * @param array<string, list<string>> $tables table => primary-key columns
     */
    private function scanAndEnqueueCompositeTables(ClusterConfig $config, array $peers, DbManifest $manifest, ConnectionInterface $db, array $tables): int
    {
        $changed = 0;

        foreach ($tables as $table => $keyColumns) {
            foreach (DbSyncSchema::exportAllCompositeKeys($db, $table, $keyColumns) as $keyValue) {
                $snapshot = DbSyncSchema::exportCompositeRow($db, $table, $keyColumns, $keyValue);
                if ($snapshot === null) {
                    continue;
                }
                $hash  = DbSyncSchema::hashSettingSnapshot($snapshot);
                $known = $manifest->get("$table:$keyValue");
                if ($known !== null && $known['hash'] === $hash) {
                    continue;
                }

                $timestamp = $this->rowTimestamp($snapshot['updated_at'] ?? null);
                $manifest->record("$table:$keyValue", ['hash' => $hash, 'timestamp' => $timestamp]);
                $this->enqueueToEveryPeer($config, $peers, $table, $keyValue, $snapshot, $timestamp);
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * The LWW timestamp a change is broadcast with MUST be when the row
     * itself was actually last written, never the scan's own wall-clock
     * moment - found live 2026-08-19: using time() here let a stale peer's
     * routine re-broadcast of unchanged old data outrace (and silently
     * revert) a genuinely newer local change, just because that peer's own
     * cron tick happened to fire later. Same real-row-timestamp approach
     * DbSyncSchema::exportEntity() already uses correctly for the
     * bootstrap/block-hash path - this brings the everyday incremental
     * path (the one actually scheduled every minute) in line with it.
     */
    private function rowTimestamp(?string $updatedAt): int
    {
        $timestamp = $updatedAt !== null ? strtotime($updatedAt) : false;

        return $timestamp !== false ? $timestamp : time();
    }

    /**
     * @param array<string, array{baseURL: string, type: string}> $peers
     */
    private function enqueueToEveryPeer(ClusterConfig $config, array $peers, string $table, string $naturalKey, array $payload, int $timestamp): void
    {
        foreach (array_keys($peers) as $peerName) {
            service('queue')->push($config->queueName, 'cluster-sync-db-row', [
                'table'      => $table,
                'naturalKey' => $naturalKey,
                'operation'  => 'upsert',
                'payload'    => $payload,
                'timestamp'  => $timestamp,
                'peer'       => $peerName,
            ]);
        }
    }

    /**
     * Bulk catch-up (`cluster:sync-db --bootstrap`) - for a brand-new
     * node's first sync, or periodic self-healing. Not scheduled by
     * default (unlike the plain scan above) - heavier than the
     * incremental path, since it enumerates every row on both sides
     * rather than just what changed since last time.
     *
     * Compares block hashes against each peer first (see DbSyncSchema::
     * computeBlockHashes()) and only fetches full row data for blocks
     * that actually differ - the rsync/Merkle-tree-style saving this
     * mode exists for. Applies through the same DbSyncSchema::
     * applyIncomingCommand() as every other path, so row-level LWW
     * behaves identically here too - a peer's stale copy of a row this
     * node already won a conflict on simply won't overwrite it.
     *
     * @param array<string, array{baseURL: string, type: string}> $peers
     */
    private function bootstrap(Cluster $cluster, array $peers, ConnectionInterface $db): void
    {
        $manifest = new DbManifest();
        $fetched  = 0;

        // genericIdBasedTables() included here too - bootstrap's own
        // block-hash compare/pull is a REQUEST this node makes (unlike
        // the outgoing scan above, which is a genuine push this node
        // initiates), so pulling one of these tables is safe regardless
        // of this node's own Source-node status: it's just asking a peer
        // "what do you have", never asserting its own copy is
        // authoritative.
        $tables = array_merge(['users', 'settings'], array_keys(DbSyncSchema::genericTables()), array_keys(DbSyncSchema::genericIdBasedTables()), array_keys(DbSyncSchema::genericCompositeKeyTables()));

        foreach ($peers as $peerName => $node) {
            $client = $cluster->peerClient($node['baseURL'], 20);

            foreach ($tables as $table) {
                try {
                    $localHashes = DbSyncSchema::computeBlockHashes($db, $table);

                    $response = $client->get('cluster/db-block-hashes', [
                        'headers' => ['Authorization' => $cluster->authHeader()],
                        'query'   => ['table' => $table],
                    ]);
                    if ($response->getStatusCode() !== 200) {
                        continue;
                    }
                    $remoteHashes = (array) (json_decode($response->getBody(), true)['blocks'] ?? []);

                    foreach ($remoteHashes as $block => $remoteHash) {
                        if (($localHashes[$block] ?? '') === $remoteHash) {
                            continue;
                        }

                        $blockResponse = $client->get('cluster/db-block-rows', [
                            'headers' => ['Authorization' => $cluster->authHeader()],
                            'query'   => ['table' => $table, 'block' => $block],
                        ]);
                        if ($blockResponse->getStatusCode() !== 200) {
                            continue;
                        }
                        $rows = (array) (json_decode($blockResponse->getBody(), true)['rows'] ?? []);

                        foreach ($rows as $row) {
                            $result = DbSyncSchema::applyIncomingCommand($db, $manifest, [
                                'table'      => $table,
                                'naturalKey' => (string) ($row['naturalKey'] ?? ''),
                                'operation'  => 'upsert',
                                'payload'    => (array) ($row['payload'] ?? []),
                                'timestamp'  => (int) ($row['timestamp'] ?? 0),
                            ], 'pull', $peerName);
                            if ($result['applied']) {
                                $fetched++;
                            }
                        }
                    }
                } catch (Throwable $e) {
                    CLI::write("cluster:sync-db --bootstrap: $peerName/$table failed - " . $e->getMessage(), 'red');
                }
            }
        }

        CLI::write("cluster:sync-db --bootstrap: $fetched row(s) applied from mismatched blocks.", $fetched > 0 ? 'green' : 'yellow');
    }
}
