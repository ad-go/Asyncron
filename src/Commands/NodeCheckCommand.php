<?php

declare(strict_types=1);

namespace AdGo\Cluster\Commands;

use AdGo\Cluster\NodeConnectivityChecker;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Runs NodeConnectivityChecker::checkAll() on a schedule - the OTHER of
 * the two triggers this feature has (see Jobs\NodeConnectivityCheckJob
 * for the per-login one). Scheduled every minute, same cadence as
 * sync-files/queue:work/cluster:pull/cluster:sync-db (see this package's
 * README) - unlike cluster:time-drift (hourly, drift barely changes
 * minute to minute), whether a node is reachable right now is exactly the
 * kind of thing worth knowing within a minute of it changing.
 *
 * Purely a report/diagnostic, same shape as TimeDriftCommand - the real
 * work (the actual connect-and-prove-usable check via whichever transport
 * each peer has configured, and recording the result) happens in
 * NodeConnectivityChecker/NodeConnectivityLog, this command just triggers
 * it and prints a human-readable summary.
 */
class NodeCheckCommand extends BaseCommand
{
    protected $group = 'Cluster';

    protected $name = 'cluster:node-check';

    protected $description = 'Test connectivity to every configured peer, via whichever transport (SSH/SCP, FTP/FTPS, or the API fallback) each one is set up for (Settings -> Nodes).';

    public function run(array $params)
    {
        $results = (new NodeConnectivityChecker())->checkAll();

        if ($results === null) {
            CLI::write('cluster:node-check: another check is already in progress, skipping.', 'yellow');

            return;
        }

        if ($results === []) {
            CLI::write('cluster:node-check: no peers configured, nothing to check.', 'yellow');

            return;
        }

        foreach ($results as $name => $entry) {
            if ($entry['ok']) {
                CLI::write(sprintf('%s: OK via %s (%.3fs)', $name, $entry['protocol'] ?? '?', $entry['latencySeconds'] ?? 0.0), 'green');
            } else {
                CLI::write("$name: FAILED - " . ($entry['error'] ?? 'unknown error'), 'red');
            }
        }
    }
}
