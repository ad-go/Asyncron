<?php

declare(strict_types=1);

/**
 * This file is part of CodeIgniter Tasks.
 *
 * (c) CodeIgniter Foundation <admin@codeigniter.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Config;

use CodeIgniter\Tasks\Config\Tasks as BaseTasks;
use CodeIgniter\Tasks\Scheduler;

class Tasks extends BaseTasks
{
    /**
     * --------------------------------------------------------------------------
     * Should performance metrics be logged
     * --------------------------------------------------------------------------
     *
     * If true, will log the time it takes for each task to run.
     * Requires the settings table to have been created previously.
     */
    public bool $logPerformance = false;

    /**
     * --------------------------------------------------------------------------
     * Maximum performance logs
     * --------------------------------------------------------------------------
     *
     * The maximum number of logs that should be saved per Task.
     * Lower numbers reduced the amount of database required to
     * store the logs.
     */
    public int $maxLogsPerTask = 10;

    /**
     * Register any tasks within this method for the application.
     * Called by the TaskRunner.
     */
    public function init(Scheduler $schedule)
    {
        // ad-go/cluster: scan writable/share/ (or whatever cluster.syncPaths
        // points at) for changes and push them to peers, then process
        // whatever got queued - both bounded (--stop-when-empty) so this
        // exits promptly instead of idling. cluster:pull runs on every node
        // (not just 'nat' ones) - it's what lets a 'nat' node receive
        // files/invalidations at all, and defense-in-depth for a 'public'
        // node against a push job that failed or crashed.
        //
        // ->singleInstance($ttl) on every everyMinute() task below - found
        // live 2026-09-02 on h1q: a large one-shot DB-sync backlog (a
        // production-DB bootstrap - see the Phase-1 migration in
        // jaunty-hugging-pascal.md) made queue:work's --stop-when-empty
        // drain take well over 60s, and TaskRunner has no built-in
        // overlap protection of its own - the crontab-triggered `php
        // spark tasks:run` invocations kept stacking (7 concurrent
        // processes observed), each competing for the same PHP-FPM/CPU
        // budget, which is what actually produced the 502s and a
        // degraded (unstyled, OPcache-starved) login page - not the sync
        // work itself. singleInstance() (codeigniter4/tasks' own
        // cache()-backed lock, checked in Task::shouldRun() BEFORE a run
        // even starts) makes an overlapping tick skip a still-running
        // task cleanly instead of starting a second copy of it - the tick
        // itself still runs promptly and still executes every OTHER due
        // task normally. TTLs are a dead-man's switch, not the expected
        // runtime - self-heal a lock left behind by a hard-killed/OOM'd
        // process (finally never ran to clear it) well inside "the
        // client is losing patience" territory, not "how long do we
        // expect this to take".
        if (class_exists(\AdGo\Cluster\Cluster::class)) {
            $schedule->command('cluster:sync-files')->everyMinute()->singleInstance(120);
            $schedule->command('cluster:sync-db')->everyMinute()->singleInstance(120);
            // Longest normal runtime of this whole list by far (drains
            // the ENTIRE pending queue, not a bounded batch, per
            // --stop-when-empty) - a longer TTL than its everyMinute()
            // siblings, matching that.
            $schedule->command('queue:work cluster-files --stop-when-empty')->everyMinute()->singleInstance(300);
            $schedule->command('cluster:pull')->everyMinute()->singleInstance(120);
            // Long-poll counterpart to cluster:pull above - holds one
            // connection open per public peer for up to ~28s instead of a
            // quick request/response, cutting worst-case NAT-relay
            // latency from "up to a full pull cadence" to "up to one
            // second" - see LongPollCommand's own docblock. Runs
            // alongside cluster:pull, not instead of it (both are safe to
            // run concurrently - every apply is idempotent).
            $schedule->command('cluster:long-poll')->everyMinute()->singleInstance(120);
            // Clock drift doesn't meaningfully change minute-to-minute the
            // way file/DB sync state does - hourly is plenty.
            $schedule->command('cluster:time-drift')->hourly()->singleInstance(600);
            // Real node connectivity (SSH/SCP, FTP/FTPS, or the API
            // fallback - whichever each peer is set up for), same
            // everyMinute() cadence as sync/pull above, not time-drift's
            // hourly one - whether a node is reachable right now is worth
            // knowing within a minute of it changing.
            $schedule->command('cluster:node-check')->everyMinute()->singleInstance(120);
        }
    }
}
