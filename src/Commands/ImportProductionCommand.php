<?php

declare(strict_types=1);

namespace AdGo\Cluster\Commands;

use AdGo\Cluster\Cluster;
use AdGo\Cluster\DbSyncSchema;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\HTTP\CURLRequest;
use Throwable;

/**
 * One-time wipe-and-clone of Config\Cluster::$dbSyncGroup's real database
 * from ONE peer explicitly marked the "Source node" (see DbSyncSchema::
 * productionSourceNodeEnabled()'s own docblock) - the "sincronizare
 * initiala (stergere si copiere)" this command exists to do: every table
 * the source reports is DROPPED and recreated on THIS node from the
 * source's own CREATE TABLE statement (Controllers\DbSyncController::
 * productionSchema()), then every row is copied over in batches
 * (::productionRows()) verbatim - autoincrement ids, every column, no
 * natural-key/LWW bookkeeping at all.
 *
 * Deliberately NOT the incremental engine (Commands\SyncDbCommand) or its
 * own bulk-catch-up mode (--bootstrap) - both of those MERGE into
 * whatever a table already has and explicitly skip autoincrement-keyed
 * tables (see DbSyncSchema::genericTables()' own docblock); this REPLACES
 * this node's own copy outright, autoincrement-keyed tables included.
 * Meant to run exactly once per node, before continuous sync ever starts
 * against it, or to re-baseline one that's drifted enough that a merge no
 * longer makes sense - not something to schedule or run repeatedly.
 *
 * Never scheduled (see app/Config/Tasks.php - absent on purpose) and
 * guarded so it can't run at all in the two situations that would matter
 * most if it did: production sync turned off locally (nothing here is
 * opted in yet), or THIS node itself marked the Source node (its own data
 * must never be at risk from a clone command, regardless of --from).
 *
 *   php spark cluster:import-production --from=node1
 *
 * Web equivalent: RouteRegistrar's own fix-import-production route (same
 * guards, for a node with no shell access).
 */
class ImportProductionCommand extends BaseCommand
{
    protected $group = 'Cluster';

    protected $name = 'cluster:import-production';

    protected $description = "One-time wipe-and-clone of this node's production database from the designated Source node.";

    protected $usage = 'cluster:import-production --from=<peer name>';

    protected $options = [
        '--from' => 'Name of the peer to clone from - that peer must have "Source node" turned on.',
    ];

    /** Rows per page fetched from the source - large enough to keep the
     * round-trip count sane for a big table (po_products-sized, tens of
     * thousands of rows), small enough that one page's JSON stays a
     * reasonable HTTP payload either direction. */
    private const PAGE_SIZE = 2000;

    public function run(array $params)
    {
        // Same reasoning asyncron.php's own long-running steps already
        // use - a web-triggered run (fix-import-production) must survive
        // past whatever timeout the client/proxy gives up at, since the
        // alternative is a half-cloned table with no way to tell from
        // outside whether it's still working or dead.
        set_time_limit(0);
        ignore_user_abort(true);

        $from = (string) (CLI::getOption('from') ?? ($params['from'] ?? ''));
        if ($from === '') {
            CLI::write('cluster:import-production: --from=<peer name> is required.', 'red');

            return;
        }

        if (! DbSyncSchema::productionSyncEnabled()) {
            CLI::write('cluster:import-production: Production sync is off on this node - turn it on first.', 'red');

            return;
        }
        if (DbSyncSchema::productionSourceNodeEnabled()) {
            CLI::write('cluster:import-production: this node is itself the Source node - refusing to overwrite it.', 'red');

            return;
        }

        $group = trim(config('Cluster')->dbSyncGroup);
        if ($group === '') {
            CLI::write("cluster:import-production: Config\\Cluster::\$dbSyncGroup is not configured.", 'red');

            return;
        }

        $cluster = new Cluster();
        $peer    = $cluster->node($from);
        if ($peer === null) {
            CLI::write("cluster:import-production: unknown peer '$from'.", 'red');

            return;
        }

        $client = $cluster->peerClient((string) $peer['baseURL'], 30);

        try {
            $response = $client->get('cluster/production-schema', [
                'headers' => ['Authorization' => $cluster->authHeader()],
            ]);
        } catch (Throwable $e) {
            CLI::write("cluster:import-production: could not reach $from - " . $e->getMessage(), 'red');

            return;
        }
        if ($response->getStatusCode() !== 200) {
            CLI::write("cluster:import-production: $from returned HTTP {$response->getStatusCode()} - is \"Source node\" turned on there?", 'red');

            return;
        }
        $schemas = (array) (json_decode($response->getBody(), true)['tables'] ?? []);
        if ($schemas === []) {
            CLI::write("cluster:import-production: $from reports no tables to clone.", 'yellow');

            return;
        }

        $db        = db_connect($group);
        $totalRows = 0;

        foreach ($schemas as $table => $createStatement) {
            CLI::write("cluster:import-production: $table - dropping and recreating...", 'yellow');

            try {
                $db->query('DROP TABLE IF EXISTS ' . $db->escapeIdentifiers($table));
                $db->query((string) $createStatement);
            } catch (Throwable $e) {
                CLI::write("cluster:import-production: $table - could not (re)create - " . $e->getMessage(), 'red');

                continue;
            }

            $tableRows = $this->copyTableRows($client, $cluster, $db, $table);
            $totalRows += $tableRows;

            CLI::write("cluster:import-production: $table - $tableRows row(s) copied.", 'green');
        }

        CLI::write('cluster:import-production: done - ' . count($schemas) . " table(s), $totalRows row(s) total from $from.", 'green');
    }

    /**
     * Pages through productionRows() until the source reports a
     * short/empty page, insertBatch()-ing each page as it arrives rather
     * than accumulating the whole table in memory first - the only
     * viable approach for a table the size of po_products (tens of
     * thousands of rows) without risking a memory-limit fatal partway
     * through.
     */
    private function copyTableRows(CURLRequest $client, Cluster $cluster, ConnectionInterface $db, string $table): int
    {
        $offset    = 0;
        $tableRows = 0;

        while (true) {
            try {
                $rowsResponse = $client->get('cluster/production-rows', [
                    'headers' => ['Authorization' => $cluster->authHeader()],
                    'query'   => ['table' => $table, 'offset' => $offset, 'limit' => self::PAGE_SIZE],
                ]);
            } catch (Throwable $e) {
                CLI::write("cluster:import-production: $table - fetch failed at offset $offset - " . $e->getMessage(), 'red');

                break;
            }
            if ($rowsResponse->getStatusCode() !== 200) {
                CLI::write("cluster:import-production: $table - fetch failed at offset $offset - HTTP {$rowsResponse->getStatusCode()}", 'red');

                break;
            }

            $rows = (array) (json_decode($rowsResponse->getBody(), true)['rows'] ?? []);
            if ($rows === []) {
                break;
            }

            try {
                $db->table($table)->insertBatch($rows);
            } catch (Throwable $e) {
                CLI::write("cluster:import-production: $table - insert failed at offset $offset - " . $e->getMessage(), 'red');

                break;
            }

            $tableRows += count($rows);
            $offset    += self::PAGE_SIZE;

            if (count($rows) < self::PAGE_SIZE) {
                break; // last page
            }
        }

        return $tableRows;
    }
}
