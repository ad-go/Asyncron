<?php

namespace Config;

use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\FrameworkException;
use CodeIgniter\HotReloader\HotReloader;

/*
 * --------------------------------------------------------------------
 * Application Events
 * --------------------------------------------------------------------
 * Events allow you to tap into the execution of the program without
 * modifying or extending core files. This file provides a central
 * location to define your events, though they can always be added
 * at run-time, also, if needed.
 *
 * You create code that can execute by subscribing to events with
 * the 'on()' method. This accepts any form of callable, including
 * Closures, that will be executed when the event is triggered.
 *
 * Example:
 *      Events::on('create', [$myInstance, 'myMethod']);
 */

Events::on('pre_system', static function (): void {
    if (ENVIRONMENT !== 'testing') {
        $value = ini_get('zlib.output_compression');

        if (filter_var($value, FILTER_VALIDATE_BOOLEAN) || (int) $value > 0) {
            throw FrameworkException::forEnabledZlibOutputCompression();
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        ob_start(static fn ($buffer) => $buffer);
    }

    /*
     * --------------------------------------------------------------------
     * Debug Toolbar Listeners.
     * --------------------------------------------------------------------
     * If you delete, they will no longer be collected.
     */
    if (CI_DEBUG && ! is_cli()) {
        Events::on('DBQuery', 'CodeIgniter\Debug\Toolbar\Collectors\Database::collect');
        service('toolbar')->respond();
        // Hot Reload route - for framework use on the hot reloader.
        if (ENVIRONMENT === 'development') {
            service('routes')->get('__hot-reload', static function (): void {
                (new HotReloader())->run();
            });
        }
    }
});

// Closes every DB connection opened during this request (both
// 'default'/'cluster' groups this app itself uses, and any DbSyncSchema
// opens dynamically for a synced group) before the response is sent -
// found live 2026-08-21 on res: a long-lived php-fpm worker leaked
// roughly one open SQLite3 file handle (writable/database.db AND
// writable/cluster.db, since both connect through the same driver) per
// request, eventually exhausting the process's open-file limit and
// crashing every route with a raw fatal error CI4 couldn't even log
// (the log handler itself needs to open a file). Root cause: CodeIgniter\
// Database\BaseConnection has no __destruct(), so the underlying SQLite3
// resource is only ever freed by PHP's cycle collector running on its own
// schedule, not synchronously at request end - see this framework's own
// Config\WorkerMode::$forceGarbageCollection, whose docblock names this
// exact "accumulate across requests" failure mode, but which only
// engages under an actual persistent worker runtime (FrankenPHP) - this
// app runs under plain php-fpm, where that mitigation never fires.
// Explicitly closing every connection here is the deterministic fix -
// each SQLite3 handle is released immediately, not whenever GC gets
// around to it.
Events::on('post_system', static function (): void {
    foreach (\Config\Database::getConnections() as $connection) {
        $connection->close();
    }
});

// ad-go/cluster's SessionInvalidationFilter reads this to tell a session
// that predates a cluster-wide password change apart from one that
// doesn't - see README "How it works", Sessions & SSO.
Events::on('login', static function ($user): void {
    if (function_exists('session')) {
        session()->set('cluster_login_at', time());
    }
    // Real node connectivity check (SSH/SCP, FTP/FTPS, or the API
    // fallback - whichever each peer is set up for), queued (not run
    // inline) right after a successful login - see README "How it
    // works", SSH connectivity checks & clock drift.
    if (class_exists(\AdGo\Cluster\Cluster::class)) {
        service('queue')->push((new \AdGo\Cluster\Cluster())->queueName(), 'cluster-node-connectivity-check', []);
    }
});