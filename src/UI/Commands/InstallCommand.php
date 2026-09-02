<?php

declare(strict_types=1);

namespace AdGo\Cluster\UI\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * The one explicit step left after `composer require ad-go/asyncron`
 * (this package is a `type: library`, `composer require`'d onto an
 * already-built app rather than being the project root itself - see
 * README's "Install order" - so unlike this package's OWN dev-harness
 * composer.json, a consuming app's composer.json has no post-install-cmd/
 * post-update-cmd hook to run this automatically). `asyncron.php` (this
 * project's own deploy tooling) runs it explicitly right after that
 * require. Safe to re-run: every step below checks its own "already done"
 * condition first, so running `php spark app:install` again later (say,
 * after pulling a framework update) just skips what's already in place
 * instead of erroring or duplicating data.
 */
class InstallCommand extends BaseCommand
{
    protected $group = 'App';

    protected $name = 'app:install';

    protected $description = 'One-shot fresh-install setup: .env, migrations, the default admin account.';

    // Deliberately NOT randomly generated - this is the one account every
    // fresh install gets, the same way a lot of self-hosted apps ship a
    // known first-login account meant to be changed afterward. Documented
    // in the README right next to the install command itself, not hidden.
    private const ADMIN_USERNAME = 'admin';
    private const ADMIN_EMAIL    = 'admin@local.host';
    private const ADMIN_PASSWORD = 'admin1234';

    // Same known-account convention as ADMIN_* above, but in the 'user'
    // group only (see AuthGroups::$defaultGroup) - a non-admin login for
    // exercising dashboard-user.php and anything else gated to regular
    // users, without needing a real invite flow.
    private const DEFAULT_USERNAME = 'user';
    private const DEFAULT_EMAIL    = 'user@local.host';
    private const DEFAULT_PASSWORD = 'user1234';

    public function run(array $params)
    {
        $this->ensureEnvFile();
        $this->ensureEncryptionKey();
        $this->ensureLoginIdentifierBoth();
        $this->ensureDatabaseEnv();

        // Everything above only WRITES to .env on disk - two separate
        // gaps stand between that and any Config object (Config\Database,
        // Config\Migrations, ...) in THIS process actually reflecting it:
        //
        // 1. .env is only ever parsed into getenv()/$_ENV/$_SERVER ONCE,
        //    at boot (CodeIgniter\Config\DotEnv, called from Boot.php) -
        //    and at boot time here, .env didn't exist yet (this is a
        //    fresh install), so that first parse loaded nothing at all.
        //    The keys ensureDatabaseEnv() etc. just wrote are still
        //    entirely absent from this process's actual environment
        //    until something parses the file again.
        // 2. CI4 caches Config objects (Factories) as singletons the
        //    FIRST time something asks for them - even with the
        //    environment now correct, an already-constructed instance
        //    doesn't retroactively update.
        //
        // Both used to be papered over the same way: passthru()'ing a
        // fresh `php spark app:install --continue` subprocess for phase
        // 2, since a brand-new process re-runs DotEnv naturally and has
        // no stale Config instances to begin with. Found live 2026-08-20
        // (before that subprocess existed) that skipping this left
        // migrations trying the framework's hardcoded MySQLi default
        // instead of the SQLite lines just written - exactly gap 1
        // above, just not yet understood as that specific mechanism.
        //
        // Removed 2026-09-01: found live on h1q that a web-triggered
        // install (PHP-FPM spawning this as a subprocess of an HTTP
        // request) can't itself spawn a FURTHER subprocess in this
        // environment ("sh: 1: : Permission denied") - passthru() being
        // unusable at all rules out subprocess re-exec as a solution
        // here, not just as unnecessary overhead. Closing both gaps
        // directly, in-process, needs no subprocess for either: re-parse
        // .env (gap 1), then reset the Factories cache (gap 2) so every
        // config()/service() call from here on constructs fresh from the
        // NOW-current environment.
        //
        // Deliberately NOT \Config\Services::reset() too, despite
        // Services (not just Factories) also caching some shared
        // instances - found live the SAME day: it clears CI4's own
        // exception-display machinery along with everything else, so any
        // *later* exception in this same process (a real migration
        // failure, say) renders as a bare, contentless `""` instead of a
        // real message - turning an easy-to-diagnose failure into a
        // near-silent one. Not needed for the actual fix anyway:
        // runMigration() below always asks for a non-shared migration
        // runner (getShared: false), which reads config(Migrations::
        // class) - Factories-cached, already covered by the reset above -
        // fresh on every call regardless of the Services cache.
        (new \CodeIgniter\Config\DotEnv(ROOTPATH))->load();
        \CodeIgniter\Config\Factories::reset();

        $this->runMigrationsAndSeedAdmin();
    }

    private function runMigrationsAndSeedAdmin(): void
    {
        CLI::write('Running migrations...', 'yellow');
        // See run()'s own docblock for why an in-process call here is
        // safe now (the Factories/Services reset it does right after
        // writing .env) - this used to need a real subprocess per
        // namespace instead, worked around a stale-config bug the reset
        // fixes at its actual root cause.
        foreach (['CodeIgniter\Shield', 'CodeIgniter\Settings', 'AdGo\Cluster\UI'] as $namespace) {
            $this->runMigration($namespace);
        }
        $this->runQueueMigrationAgainstClusterGroup();

        $this->ensureAdminAccount();
        $this->ensureDefaultUserAccount();
        $this->ensureWorldWritablePermissions();

        CLI::newLine();
        CLI::write('Install complete.', 'green');
        CLI::write('Log in at /login with:', 'green');
        CLI::write('  email:    ' . self::ADMIN_EMAIL);
        CLI::write('  password: ' . self::ADMIN_PASSWORD);
        CLI::write('or as a regular user:', 'green');
        CLI::write('  email:    ' . self::DEFAULT_EMAIL);
        CLI::write('  password: ' . self::DEFAULT_PASSWORD);
        CLI::write('Change both passwords from the Profile page before this goes anywhere near the public internet.', 'yellow');
        CLI::newLine();
        CLI::write('Point your webserver at public/, then start the scheduler and queue worker (see README\'s "Running it" section) - nothing here runs on its own without them.', 'yellow');
    }

    // This install can be triggered by different system users depending on
    // how it's kicked off (an interactive/SSH shell vs a subprocess PHP-FPM
    // spawns for a web-triggered install) - found live 2026-08-20 on a node
    // where the SSH user and the PHP-FPM user are two unrelated accounts
    // with no shared group. Whichever of the two runs THIS install owns
    // every file composer/this command just created; without this, a
    // LATER reinstall triggered by the OTHER user/transport fails partway
    // through with "Permission denied" trying to wipe or overwrite them.
    // chmod'ing everything a+rwX here means either one can always pick up
    // cleanly after the other, regardless of which one ran last. Best-
    // effort and non-fatal - not being able to broaden permissions here
    // doesn't mean the install itself failed.
    private function ensureWorldWritablePermissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // No meaningful chmod semantics on Windows - nothing to do.
            return;
        }

        // A bare exec('chmod -R ...') depends on the invoking process's
        // PATH having chmod on it - found live 2026-08-22: this same
        // install, triggered over HTTP (PHP-FPM spawns this command as a
        // subprocess of a web request), failed with exit 127 ("command
        // not found") because php-fpm's own exec() environment doesn't
        // inherit a full shell PATH. PHP's own chmod() has no such
        // dependency, so walk the tree directly instead of shelling out.
        $failures = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(ROOTPATH, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $path) {
                if (!@chmod($path->getPathname(), $path->isDir() ? 0777 : 0666)) {
                    $failures++;
                }
            }
            if (!@chmod(ROOTPATH, 0777)) {
                $failures++;
            }
        } catch (\Throwable $e) {
            CLI::write('WARNING: could not set a+rwX permissions on the app root (' . $e->getMessage() . ') - not fatal, but a future reinstall triggered by a different system user may hit permission errors.', 'yellow');

            return;
        }

        if ($failures > 0) {
            CLI::write("WARNING: {$failures} path(s) under the app root could not be made a+rwX (likely owned by a different system user) - not fatal, but a future reinstall triggered by that user may hit permission errors.", 'yellow');

            return;
        }
        CLI::write('Whole app tree set to a+rwX (so a future install by a different system user/transport can always overwrite it).', 'green');
    }

    // In-process equivalent of `php spark migrate -n $namespace` (see
    // vendor/codeigniter4/framework/system/Commands/Database/Migrate.php's
    // own run() for the exact sequence this mirrors: setNamespace() then
    // latest()). Used to be a real `passthru()` subprocess instead - see
    // run()'s own docblock for why that's no longer viable here at all
    // (not just unnecessary), and why the Factories/Services reset it
    // does once at the top of this whole command is what makes an
    // in-process call here safe: a NON-shared runner (getShared: false)
    // still reads its own config('Migrations')/database group fresh via
    // that reset, so this doesn't depend on run()'s reset happening to
    // still be "fresh enough" several calls later.
    // $exitOnFailure=false is for runQueueMigrationAgainstClusterGroup()'s
    // own first attempt ONLY - see that method's own docblock. Found live
    // 2026-09-01 reinstalling beta: this method's own exit(1) on failure
    // made bootstrapLegacySqliteQueuePriority() (right after that first
    // call) unreachable dead code on exactly the legacy-SQLite build it
    // was written for - the process was already gone before control could
    // ever get there, so the self-heal never ran and the whole install
    // died instead of recovering. Every other call site (the Shield/
    // Settings/AdGo\Cluster\UI loop below) keeps the original hard-exit
    // behavior; a genuine migration failure there has no self-heal to
    // attempt and should still fail the install loudly and immediately.
    private function runMigration(string $namespace, ?string $group = null, bool $exitOnFailure = true): bool
    {
        try {
            // Constructing MigrationRunner directly, not via
            // \Config\Services::migrations(null, $group, false) - that
            // facade type-hints its own $db param as
            // ?ConnectionInterface and rejects a bare group-name string
            // outright (TypeError), even though MigrationRunner's OWN
            // constructor has no such restriction and handles a string
            // group name natively: `$this->group = is_string($db) ?
            // $db : ...; $this->db = db_connect($db);` - exactly what
            // runQueueMigrationAgainstClusterGroup() needs (its own
            // docblock explains why THIS, not latest($group), is what
            // actually redirects which connection migrations run
            // against).
            $runner = new \CodeIgniter\Database\MigrationRunner(config(\Config\Migrations::class), $group);
            $runner->setNamespace($namespace);

            if (! $runner->latest()) {
                CLI::error("migrate -n $namespace failed: no result from the migration runner.");
                if ($exitOnFailure) {
                    exit(1);
                }

                return false;
            }
        } catch (\Throwable $e) {
            CLI::error("migrate -n $namespace failed: [" . get_class($e) . '] ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            if ($exitOnFailure) {
                exit(1);
            }

            return false;
        }

        foreach ($runner->getCliMessages() as $message) {
            CLI::write($message);
        }

        return true;
    }

    // codeigniter4/queue's own migrations don't declare a $DBGroup, so a
    // plain runMigration('CodeIgniter\Queue') (no explicit group) connects
    // via Config\Database's OWN defaultGroup - 'default' (database.db),
    // not 'cluster' (cluster.db) - even though Config\Queue.php's own
    // $dbGroup for RUNTIME queue operations IS 'cluster'. Found live
    // 2026-08-20 reinstalling h1q: queue_jobs ended up in database.db,
    // and queue:work then failed every run with "no such table:
    // queue_jobs" reading from the group that was never actually
    // migrated.
    //
    // MigrationRunner's own $group (used for both the migration HISTORY
    // table AND which connection actual CREATE TABLE/ALTER TABLE
    // statements run against) is fixed at CONSTRUCTION time from its
    // constructor's own $db param, not from latest($group)'s - see that
    // method's own signature: passing a group there only changes which
    // group's history gets checked, never which connection the real
    // schema changes run against. So the fix is passing 'cluster'
    // through runMigration()'s own $group param above, all the way to
    // Services::migrations()'s $db param (a group name there, not a
    // connection object - see MigrationRunner::__construct()'s own
    // `$this->db = db_connect($db);`, which resolves a string the same
    // way any other db_connect('cluster') call would: Config\Database's
    // OWN independent 'cluster' connection, already correctly pointed at
    // cluster.db by ensureDatabaseEnv() - not the .env-swap-and-restore
    // dance an earlier version of this method used, working around the
    // exact same problem this way DIDN'T have (no global state to get
    // wrong, nothing to restore, nothing racy if this ever runs twice).
    private function runQueueMigrationAgainstClusterGroup(): void
    {
        // exitOnFailure: false - this first attempt is EXPECTED to fail
        // outright on a legacy SQLite build (see
        // bootstrapLegacySqliteQueuePriority()'s own docblock); the normal
        // hard-exit behavior would kill the process before the self-heal
        // below ever got a chance to run. bootstrapLegacySqliteQueuePriority()
        // itself decides from actual DB state whether there's anything to
        // fix, not from this call's return value, so a genuine success
        // here is still handled correctly (it just no-ops).
        $this->runMigration('CodeIgniter\Queue', 'cluster', exitOnFailure: false);

        // config(Database::class)->cluster['database'] is exactly the
        // "cluster.db" value ensureDatabaseEnv() wrote - fresh here
        // thanks to run()'s own Factories::reset(). WRITEPATH, not
        // ROOTPATH - CI4's own SQLite3 driver only treats a bare
        // filename with no '/' in it (exactly what ensureDatabaseEnv()
        // writes) as relative to WRITEPATH ('<root>/writable/'), never
        // the app root itself. Found live 2026-08-20: using ROOTPATH
        // here pointed at a file that never existed, so
        // bootstrapLegacySqliteQueuePriority() silently no-op'd via its
        // own is_file() guard - looked like nothing was wrong, just
        // never fired.
        $clusterValue  = (string) config(\Config\Database::class)->cluster['database'];
        $clusterDbPath = (str_starts_with($clusterValue, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $clusterValue) === 1)
            ? $clusterValue
            : WRITEPATH . $clusterValue;

        if ($this->bootstrapLegacySqliteQueuePriority($clusterDbPath)) {
            // AddPriorityField crashed before it could run (see that
            // method's own docblock), which means `migrate` stopped
            // right there - anything queued AFTER it (e.g.
            // ChangePayloadFieldTypeInSqlsrv) never got a chance to run
            // either. Re-running now that AddPriorityField is marked
            // applied lets the rest of the batch proceed.
            $this->runMigration('CodeIgniter\Queue', 'cluster');
        }
    }

    // codeigniter4/queue's own AddPriorityField migration introspects the
    // table's existing indexes via the pragma_index_list()/pragma_index_info()
    // SQLite table-valued functions - only added in SQLite 3.16 (2017).
    // Some shared-hosting SQLite builds still in active use are far older
    // (found live 2026-08-20: beta.romania-fulfillment.ro's cPanel PHP 8.3
    // ships SQLite 3.7.17, from 2013) and reject that syntax outright -
    // "Unable to prepare statement: near '(': syntax error" - so the
    // migration's own up() throws before it can ALTER TABLE. queue_jobs is
    // left without its priority column (Queue silently can't be used) and
    // migrate moves on rather than treating it as fatal.
    //
    // Detects that exact gap - queue_jobs exists (AddQueueTables, the
    // migration right before this one, already ran) but has no priority
    // column, and AddPriorityField isn't recorded as applied - and applies
    // the equivalent schema change by hand with plain ALTER TABLE/CREATE
    // INDEX (supported since SQLite's own beginning, no pragma_* functions
    // involved), then records the migration as applied so CI4's own
    // migration runner doesn't try it again. Returns true when it actually
    // had to intervene, so the caller knows to re-run the migration batch
    // for whatever came after AddPriorityField and never got a chance to
    // run either.
    private function bootstrapLegacySqliteQueuePriority(string $databasePath): bool
    {
        if (! is_file($databasePath) || ! class_exists(\SQLite3::class)) {
            return false;
        }

        $sqlite  = new \SQLite3($databasePath);
        $version = '2023-11-05-064053';
        $class   = 'CodeIgniter\Queue\Database\Migrations\AddPriorityField';

        $recorded = $sqlite->prepare('SELECT 1 FROM migrations WHERE version = :version AND class = :class');
        $recorded->bindValue(':version', $version, SQLITE3_TEXT);
        $recorded->bindValue(':class', $class, SQLITE3_TEXT);
        $migrationRecorded = $recorded->execute()->fetchArray(SQLITE3_NUM) !== false;

        if ($migrationRecorded) {
            $sqlite->close();

            return false;
        }

        $hasPriority = false;
        $columns     = $sqlite->query('PRAGMA table_info(queue_jobs)');
        if ($columns !== false) {
            while ($column = $columns->fetchArray(SQLITE3_ASSOC)) {
                if (($column['name'] ?? '') === 'priority') {
                    $hasPriority = true;
                    break;
                }
            }
        }
        if (! $hasPriority) {
            $sqlite->exec("ALTER TABLE queue_jobs ADD COLUMN priority VARCHAR(64) NOT NULL DEFAULT 'default'");
            $sqlite->exec("ALTER TABLE queue_jobs_failed ADD COLUMN priority VARCHAR(64) NOT NULL DEFAULT 'default'");
        }
        $sqlite->exec('CREATE INDEX IF NOT EXISTS queue_priority_status_available_at ON queue_jobs (queue, priority, status, available_at)');

        $statement = $sqlite->prepare(
            'INSERT INTO migrations (version, class, "group", namespace, time, batch)
             SELECT :version, :class, :group, :namespace, :time, COALESCE((SELECT MAX(batch) + 1 FROM migrations), 1)
             WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE version = :version AND class = :class)'
        );
        $statement->bindValue(':version', $version, SQLITE3_TEXT);
        $statement->bindValue(':class', $class, SQLITE3_TEXT);
        $statement->bindValue(':group', 'cluster', SQLITE3_TEXT);
        $statement->bindValue(':namespace', 'CodeIgniter\Queue', SQLITE3_TEXT);
        $statement->bindValue(':time', time(), SQLITE3_INTEGER);
        $statement->execute();
        $sqlite->close();

        CLI::write('Queue priority migration bootstrapped by hand (legacy SQLite build - no pragma_index_list() support).', 'green');

        return true;
    }

    private function ensureEnvFile(): void
    {
        $envPath      = ROOTPATH . '.env';
        $templatePath = ROOTPATH . 'env';

        if (is_file($envPath)) {
            CLI::write('.env already exists, left untouched.', 'yellow');

            return;
        }
        if (! is_file($templatePath)) {
            CLI::error('No .env and no env template found - cannot continue.');
            exit(1);
        }

        copy($templatePath, $envPath);
        CLI::write('.env created from the env template.', 'green');
        $this->ensureCleanClusterState();
    }

    // Guards against a residual-file bug found live 2026-08-21 on bak: the
    // external wipe tool this project's own deploy tooling uses (asyncron.php,
    // NOT part of this repo) deletes the app tree file-by-file before a
    // fresh `composer create-project`, but a permission split between the
    // transport user (SSH) and the PHP-FPM runtime user - the same split
    // documented on Cluster's own writable/Cluster/*.json state files,
    // each owned by whichever user last ran queue:work/tasks:run - can
    // leave individual files behind (a failed delete, not a skipped one;
    // the external tool's own "N failed" count already flags this, but
    // nothing downstream acted on it before now).
    //
    // Only ever runs from ensureEnvFile()'s fresh-install branch, i.e.
    // exactly when .env didn't exist yet - never on a re-run against an
    // already-configured node (see this class's own docblock: app:install
    // must stay idempotent/safe to run again after a framework update). A
    // genuinely fresh install must never inherit ANOTHER install's
    // sync-state cache: a stale files_manifest.json entry for a file this
    // fresh install never actually synced would make SyncFilesCommand
    // treat it as "already synced" and silently skip re-pushing it once
    // cluster.nodes is configured again.
    private function ensureCleanClusterState(): void
    {
        $dir = WRITEPATH . 'Cluster';
        if (! is_dir($dir)) {
            return;
        }

        $removed = 0;
        foreach (glob($dir . '/*') ?: [] as $path) {
            if (is_file($path) && @unlink($path)) {
                $removed++;
            }
        }

        if ($removed > 0) {
            CLI::write("$removed stale writable/Cluster/* file(s) from a previous install removed.", 'yellow');
        }
    }

    private function ensureEncryptionKey(): void
    {
        $envPath = ROOTPATH . '.env';
        $env     = file_get_contents($envPath);

        if (preg_match('/^\s*encryption\.key\s*=\s*\S+/m', $env) === 1) {
            CLI::write('encryption.key already set, left untouched.', 'yellow');

            return;
        }

        $key = 'base64:' . base64_encode(random_bytes(32));
        file_put_contents($envPath, rtrim($env) . "\nencryption.key = $key\n");
        CLI::write('encryption.key generated.', 'green');
    }

    // This app's login view submits which identifier mode the user picked
    // (email or username) - see AdGo\Cluster\UI\Controllers\AuthController::loginAction() -
    // which only makes sense when Shield's own loginIdentifier is 'both'.
    // Shield's own default is 'email' only.
    private function ensureLoginIdentifierBoth(): void
    {
        $envPath = ROOTPATH . '.env';
        $env     = file_get_contents($envPath);

        if (preg_match('/^\s*auth\.loginIdentifier\s*=/m', $env) === 1) {
            CLI::write('auth.loginIdentifier already set, left untouched.', 'yellow');

            return;
        }

        file_put_contents($envPath, rtrim($env) . "\nauth.loginIdentifier = both\n");
        CLI::write('auth.loginIdentifier set to both.', 'green');
    }

    // No real database chosen yet defaults to SQLite - zero setup needed
    // to get a working install, same as codeigniter4/appstarter's own
    // convention. A 'cluster' group too, alongside 'default': the Queue
    // package's own jobs table and ad-go/cluster's DB-sync state
    // deliberately live in a SEPARATE connection from the app's own
    // Shield/Settings/Users tables (see this app's Config/Queue.php),
    // so an app that later points database.default at a real MySQL/
    // Postgres server for its own data still keeps queue/sync state local.
    private function ensureDatabaseEnv(): void
    {
        $envPath = ROOTPATH . '.env';
        $env     = file_get_contents($envPath);

        if (preg_match('/^\s*database\.default\.DBDriver\s*=/m', $env) === 1) {
            CLI::write('database.default.DBDriver already set, left untouched.', 'yellow');

            return;
        }

        $env = rtrim($env) . "\n"
            . "database.default.hostname = localhost\n"
            . "database.default.database = database.db\n"
            . "database.default.DBDriver = SQLite3\n"
            . "database.default.DBPrefix =\n"
            . "database.cluster.hostname = localhost\n"
            . "database.cluster.database = cluster.db\n"
            . "database.cluster.DBDriver = SQLite3\n"
            . "database.cluster.DBPrefix =\n";
        file_put_contents($envPath, $env);
        CLI::write('database.default/cluster set to SQLite (database.db / cluster.db) - point at a real server later if you need one.', 'green');
    }

    // Config\App::$baseURL defaults to the framework's own compiled-in
    // 'http://localhost:8080/' and nothing else here ever touches it,
    // which is deliberate: an EMPTY baseURL (CodeIgniter's own documented
    // "auto-detect from the current request" behavior) was tried live
    // 2026-08-20 and made `php spark app:install`/`migrate` itself crash
    // outright - "Config\App::$baseURL "/" is not a valid URL" - because
    // this app's own CLI bootstrap builds a Request/URI service too
    // (unlike a plain web request, a CLI process has no real Host header
    // to detect from, so CI4's own normalizeBaseURL() has nothing to
    // resolve an empty value against and produces an invalid one). This
    // app has no reliable way to know a given node's real public URL at
    // CLI-install time anyway (SSH has no HTTP context at all; a web-
    // triggered install's own Host header isn't threaded through to this
    // subprocess) - setting the CORRECT per-node value belongs to
    // whatever deploys this app (it already knows each node's real URL),
    // written directly into .env AFTER this command has finished, same as
    // every other node-specific value (cluster.* peer config, etc.).

    private function ensureAdminAccount(): void
    {
        $users = model(UserModel::class);

        if ($users->where('username', self::ADMIN_USERNAME)->first() !== null) {
            CLI::write('Admin account already exists, left untouched.', 'yellow');

            return;
        }

        $user = new User([
            'username' => self::ADMIN_USERNAME,
            'active'   => 1,
        ]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity([
            'email'    => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);
        $user->addGroup('user');
        $user->addGroup('superadmin');

        CLI::write('Admin account created (' . self::ADMIN_EMAIL . ').', 'green');
    }

    // Mirrors ensureAdminAccount() - same known-account/idempotent-check
    // shape, just DEFAULT_* instead of ADMIN_* and no superadmin group.
    private function ensureDefaultUserAccount(): void
    {
        $users = model(UserModel::class);

        if ($users->where('username', self::DEFAULT_USERNAME)->first() !== null) {
            CLI::write('Default user account already exists, left untouched.', 'yellow');

            return;
        }

        $user = new User([
            'username' => self::DEFAULT_USERNAME,
            'active'   => 1,
        ]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity([
            'email'    => self::DEFAULT_EMAIL,
            'password' => self::DEFAULT_PASSWORD,
        ]);
        $user->addGroup('user');

        CLI::write('Default user account created (' . self::DEFAULT_EMAIL . ').', 'green');
    }
}
