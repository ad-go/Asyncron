<?php

declare(strict_types=1);

namespace AdGo\Cluster\Controllers;

use AdGo\Cluster\DbManifest;
use AdGo\Cluster\DbSyncSchema;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Server side of DB sync - both the incremental push receiver (receive())
 * and the pull/bulk-catch-up counterparts a peer calls to read state from
 * this node. Auth is the ClusterAuthFilter (Bearer token), not Shield -
 * node-to-node traffic, same as every other cluster/* peer route.
 */
class DbSyncController extends Controller
{
    /**
     * Push receiver - one write command per request (see Jobs\
     * SyncDbRowJob). Row-level LWW + applying it both happen inside
     * DbSyncSchema::applyIncomingCommand() - this controller is a thin
     * wrapper, same shape as FileReceiverController's own receive().
     */
    public function receive(): ResponseInterface
    {
        $command = json_decode($this->request->getBody(), true);
        if (! is_array($command)) {
            return $this->response->setStatusCode(400)->setJSON(['error' => 'invalid body']);
        }

        $db       = db_connect('default');
        $manifest = new DbManifest();
        // Self-reported, not authenticated - same reasoning as
        // FileReceiverController's own 'peer' field handling: purely a
        // Dashboard label (see SyncState's docblock for the fuller
        // reasoning file sync already established for this).
        $peer     = (string) ($command['peer'] ?? '');
        $result   = DbSyncSchema::applyIncomingCommand($db, $manifest, $command, 'push-in', $peer);

        return $this->response->setJSON($result);
    }

    /**
     * Pull counterpart to PullController::files() - unlike file bytes
     * (potentially large, hence a separate pull-file fetch per path), a
     * DB entity's snapshot is small JSON, so the full payload is returned
     * directly here rather than in a second round trip.
     *
     * @return ResponseInterface {"rows": [{"table":..., "naturalKey":..., "timestamp":..., "payload": {...}}, ...]}
     */
    public function pullRows(): ResponseInterface
    {
        $since    = (int) $this->request->getGet('since');
        $manifest = new DbManifest();
        $db       = db_connect('default');

        return $this->response->setJSON(['rows' => DbSyncSchema::collectRowsSince($db, $manifest, $since)]);
    }

    /**
     * Bulk catch-up, step 1 - block hashes only, so a peer with mostly-
     * matching data never has to transfer full rows for blocks that
     * already agree. See DbSyncSchema::computeBlockHashes()'s own
     * docblock for the bucketing/hashing scheme.
     *
     * @return ResponseInterface {"blocks": {"0": "hash", "1": "hash", ...}}
     */
    public function blockHashes(): ResponseInterface
    {
        $table = (string) $this->request->getGet('table');
        if (! in_array($table, ['users', 'settings'], true) && ! array_key_exists($table, DbSyncSchema::genericTables()) && ! array_key_exists($table, DbSyncSchema::genericIdBasedTables())) {
            return $this->response->setStatusCode(400)->setJSON(['error' => 'unknown table']);
        }

        $db = db_connect('default');

        return $this->response->setJSON(['blocks' => DbSyncSchema::computeBlockHashes($db, $table)]);
    }

    /**
     * Bulk catch-up, step 2 - full row data for ONE block, fetched only
     * for blocks a peer's own hash comparison found different.
     *
     * @return ResponseInterface {"rows": [{"naturalKey":..., "payload": {...}, "timestamp": ...}, ...]}
     */
    public function blockRows(): ResponseInterface
    {
        $table = (string) $this->request->getGet('table');
        $block = (int) $this->request->getGet('block');
        // Was 'users'/'settings' only, before $dbSyncGroup existed - a
        // real gap even for the old $dbSyncTables mechanism this replaces
        // (never caught live since that was confirmed unused on every
        // node), matched to blockHashes()'s own (already-correct) check.
        // Without this, a peer's block-hash mismatch for a generic table
        // (see SyncDbCommand::bootstrap()) could NEVER be resolved -
        // blockHashes() would report the mismatch but this endpoint would
        // 400 on step 2, every time.
        if (! in_array($table, ['users', 'settings'], true) && ! array_key_exists($table, DbSyncSchema::genericTables()) && ! array_key_exists($table, DbSyncSchema::genericIdBasedTables())) {
            return $this->response->setStatusCode(400)->setJSON(['error' => 'unknown table']);
        }

        $db   = db_connect('default');
        $rows = [];
        foreach (DbSyncSchema::naturalKeysInBlock($db, $table, $block) as $naturalKey) {
            $entity = DbSyncSchema::exportEntity($db, $table, $naturalKey);
            if ($entity === null) {
                continue;
            }
            $rows[] = [
                'naturalKey' => $naturalKey,
                'payload'    => $entity['payload'],
                'timestamp'  => $entity['timestamp'],
            ];
        }

        return $this->response->setJSON(['rows' => $rows]);
    }

    /**
     * Step 1 of Commands\ImportProductionCommand's own clone flow - every
     * CREATE TABLE statement for THIS node's real Config\Cluster::
     * $dbSyncGroup database. Only served when this node is itself marked
     * the "Source node" (see DbSyncSchema::productionSourceNodeEnabled()'s
     * own docblock) - a peer has no business cloning FROM a node that
     * isn't the one an admin actually designated as authoritative, even
     * if that peer somehow already has this endpoint's URL.
     */
    public function productionSchema(): ResponseInterface
    {
        if (! DbSyncSchema::productionSourceNodeEnabled()) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'not a source node']);
        }

        return $this->response->setJSON(['tables' => DbSyncSchema::productionTableSchemas()]);
    }

    /**
     * Step 2 - one page of raw rows for ONE table, same source-node gate
     * as productionSchema() above. Deliberately a flat array of column =>
     * value (not the {naturalKey, payload, timestamp} shape blockRows()
     * above uses) - a full clone copies every column of every row
     * verbatim, including whatever autoincrement id the source has,
     * unlike the incremental engine's own natural-key/LWW bookkeeping.
     */
    public function productionRows(): ResponseInterface
    {
        if (! DbSyncSchema::productionSourceNodeEnabled()) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'not a source node']);
        }

        $table  = (string) $this->request->getGet('table');
        $offset = max(0, (int) $this->request->getGet('offset'));
        $limit  = min(5000, max(1, (int) $this->request->getGet('limit')));

        return $this->response->setJSON(['rows' => DbSyncSchema::productionTableRows($table, $offset, $limit)]);
    }
}
