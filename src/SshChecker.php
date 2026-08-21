<?php

declare(strict_types=1);

namespace AdGo\Cluster;

use AdGo\Cluster\Config\Cluster as ClusterConfig;
use CodeIgniter\Settings\Settings;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * Real SSH connectivity check against every peer that HAS SSH credentials
 * configured (cluster-ui's Settings -> Nodes table, `Nodes.sshHost` etc.,
 * one of the two independent credential sets per node - see
 * SettingsController::NODE_PROPS's own docblock in ad-go/cluster-ui).
 * A node with no sshHost set (today: beta, which only ever had FTP/FTPS)
 * is silently skipped, not reported as a failure - "not configured" and
 * "configured but unreachable" are different things worth telling apart.
 *
 * Uses phpseclib3 (a pure-PHP SSH2 client), not the `ssh2` PECL extension
 * or shelling out to a system ssh/scp binary - found live 2026-08-19 that
 * beta's shared-hosting PHP build has neither the ssh2 extension nor
 * `sshpass` (needed for a non-interactive password login via a bare CLI
 * ssh binary), while phpseclib worked correctly using only openssl/
 * bcmath, both already present.
 *
 * A login-success check alone can't tell "authenticated" from "logged in
 * but the shell is somehow unusable" apart, so this also runs one trivial
 * command (`true`) as a real, cheap proof the connection is actually
 * usable, not just that credentials were accepted.
 *
 * Triggered two ways, both calling checkAll() - see Jobs\
 * SshConnectivityCheckJob (queued right after a login - see README "How
 * it works", SSH connectivity checks & clock drift) and Commands\
 * SshCheckCommand (scheduled every minute).
 */
class SshChecker
{
    private ClusterConfig $config;

    private SshConnectivityLog $log;

    public function __construct(?ClusterConfig $config = null, ?SshConnectivityLog $log = null)
    {
        $this->config = $config ?? config('Cluster');
        $this->log    = $log ?? new SshConnectivityLog($this->config);
    }

    /**
     * @return array<string, array{checkedAt: int, ok: bool, latencySeconds?: float, error?: string}>|null
     *               keyed by node name - only nodes with SSH credentials
     *               configured are present at all. NULL (not an empty
     *               array - that already means "no node has SSH
     *               credentials configured", a real, different outcome
     *               SshCheckCommand reports on) when another checkAll() is
     *               already in progress - see acquireLock().
     */
    public function checkAll(): ?array
    {
        // Same flock() convention as PullCommand::acquireLock() - without
        // it, a login-triggered SshConnectivityCheckJob and the scheduled
        // `cluster:ssh-check` tick can run concurrently against the same
        // peers (found live 2026-08-20 auditing this class: PullCommand/
        // LongPollCommand both guard their own runs this way, this one
        // never did). Harmless today (each check is independent, no shared
        // state written mid-run beyond the log), but wasteful - two SSH
        // handshakes to the same peer at once for zero extra information.
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
            // 'nat' peers on the same private LAN can often reach each other
            // over SSH even though neither can push files to the other over
            // the public internet, so filtering by type here would silently
            // miss real, useful results.
            foreach ($cluster->allPeers() as $name => $node) {
                $host = trim((string) ($settings->get('Nodes.sshHost', $name) ?? ''));
                if ($host === '') {
                    continue;
                }
                $results[$name] = $this->checkNode($name, $host, $settings);
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
        $path = rtrim(WRITEPATH, '/\\') . '/' . trim($this->config->stateDir, '/\\') . '/ssh-check.lock';
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
     * @return array{checkedAt: int, ok: bool, latencySeconds?: float, error?: string}
     */
    private function checkNode(string $name, string $host, Settings $settings): array
    {
        $port = (int) ($settings->get('Nodes.sshPort', $name) ?: 22);
        $user = (string) ($settings->get('Nodes.sshUser', $name) ?? '');
        $pass = (string) ($settings->get('Nodes.sshPass', $name) ?? '');

        $start = microtime(true);
        try {
            $ssh      = new SSH2($host, $port, 10);
            $loggedIn = $ssh->login($user, $pass);
            if (! $loggedIn) {
                $entry = ['checkedAt' => time(), 'ok' => false, 'error' => 'authentication failed'];
                $this->log->record($name, $entry);

                return $entry;
            }

            // Cheap, side-effect-free - just confirms the shell actually
            // runs commands, not only that the handshake/auth succeeded.
            $ssh->exec('true');
            $exitStatus = $ssh->getExitStatus();
            $ssh->disconnect();

            if ($exitStatus !== 0) {
                $entry = ['checkedAt' => time(), 'ok' => false, 'error' => "login succeeded but exec failed (exit $exitStatus)"];
                $this->log->record($name, $entry);

                return $entry;
            }

            $entry = ['checkedAt' => time(), 'ok' => true, 'latencySeconds' => round(microtime(true) - $start, 3)];
            $this->log->record($name, $entry);

            return $entry;
        } catch (Throwable $e) {
            $entry = ['checkedAt' => time(), 'ok' => false, 'error' => $e->getMessage()];
            $this->log->record($name, $entry);

            return $entry;
        }
    }
}
