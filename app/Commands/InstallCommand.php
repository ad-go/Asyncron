<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Runs automatically after `composer create-project` (see composer.json's
 * post-create-project-cmd) - the whole point of this command existing is
 * that a fresh install needs exactly ONE step (that composer command
 * itself), not a checklist of `php spark` calls to run by hand afterward.
 * Safe to re-run: every step below checks its own "already done" condition
 * first, so running `php spark app:install` again later (say, after
 * pulling a framework update) just skips what's already in place instead
 * of erroring or duplicating data.
 */
class InstallCommand extends BaseCommand
{
    protected $group = 'App';

    protected $name = 'app:install';

    protected $description = 'One-shot fresh-install setup: .env, migrations, the default admin account.';

    protected $options = [
        '--continue' => 'Internal - re-invoked as a fresh subprocess once .env is written, so migrations/the admin account see the up-to-date config. Not meant to be passed by hand.',
    ];

    // Deliberately NOT randomly generated - this is the one account every
    // fresh install gets, the same way a lot of self-hosted apps ship a
    // known first-login account meant to be changed afterward. Documented
    // in the README right next to the install command itself, not hidden.
    private const ADMIN_USERNAME = 'admin';
    private const ADMIN_EMAIL    = 'admin@local.host';
    private const ADMIN_PASSWORD = 'admin1234';

    public function run(array $params)
    {
        // Two phases, deliberately in TWO processes: .env is read once, at
        // boot, by the CLI SAPI that's already running by the time this
        // method starts - writing new lines into the file below does NOT
        // retroactively change THIS process's own Config\Database (or
        // anything else already booted from the old values). Found live
        // 2026-08-20 testing a from-scratch install: migrations kept
        // trying the framework's hardcoded MySQLi default instead of the
        // SQLite lines just written, because they ran in-process via
        // command(), not a fresh php invocation. Re-executing this same
        // command as a real subprocess for phase 2 is what actually picks
        // up the freshly-written .env - a fresh process boots from the
        // file on disk, not this process's already-cached environment.
        if (CLI::getOption('continue') !== null) {
            $this->runMigrationsAndSeedAdmin();

            return;
        }

        $this->ensureEnvFile();
        $this->ensureEncryptionKey();
        $this->ensureLoginIdentifierBoth();
        $this->ensureDatabaseEnv();

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOTPATH . 'spark') . ' app:install --continue';
        passthru($cmd, $exitCode);
        if ($exitCode !== 0) {
            CLI::error('Phase 2 (migrations/admin account) failed - see output above.');
            exit($exitCode);
        }
    }

    private function runMigrationsAndSeedAdmin(): void
    {
        CLI::write('Running migrations...', 'yellow');
        // Real subprocesses, not the in-process command() helper - found
        // live 2026-08-20 that command('migrate -n CodeIgniter\Shield')
        // silently found zero migrations to run (no error, just nothing),
        // while the exact same call as a real `php spark migrate -n ...`
        // subprocess correctly found and ran CreateAuthTables. Matches
        // the same reasoning ad-go's own install tooling already settled
        // on for every migrate call it makes.
        foreach (['CodeIgniter\Shield', 'CodeIgniter\Settings', 'App'] as $namespace) {
            $this->runMigration($namespace);
        }
        $this->runQueueMigrationAgainstClusterGroup();

        $this->ensureAdminAccount();
        $this->ensureWorldWritablePermissions();

        CLI::newLine();
        CLI::write('Install complete.', 'green');
        CLI::write('Log in at /login with:', 'green');
        CLI::write('  email:    ' . self::ADMIN_EMAIL);
        CLI::write('  password: ' . self::ADMIN_PASSWORD);
        CLI::write('Change that password from the Profile page before this goes anywhere near the public internet.', 'yellow');
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

        exec('chmod -R a+rwX ' . escapeshellarg(ROOTPATH) . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            CLI::write('WARNING: chmod -R a+rwX on the app root failed (exit ' . $exitCode . ') - not fatal, but a future reinstall triggered by a different system user may hit permission errors.', 'yellow');

            return;
        }
        CLI::write('Whole app tree set to a+rwX (so a future install by a different system user/transport can always overwrite it).', 'green');
    }

    private function runMigration(string $namespace): void
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOTPATH . 'spark') . ' migrate -n ' . escapeshellarg($namespace);
        passthru($cmd, $exitCode);
        if ($exitCode !== 0) {
            CLI::error("migrate -n $namespace failed (exit $exitCode).");
            exit($exitCode);
        }
    }

    // codeigniter4/queue's own migrations don't declare a $DBGroup, so
    // `migrate -n CodeIgniter\Queue -g cluster` does NOT redirect them -
    // MigrationRunner's connection is fixed at construction time from
    // Config\Database's default group, and -g only changes which group's
    // migration HISTORY gets checked/written, never which connection the
    // actual CREATE TABLE/ALTER TABLE statements run against. Found live
    // 2026-08-20 reinstalling h1q: queue_jobs ended up in database.db
    // (the 'default' group) while Config\Queue.php's own dbGroup is
    // 'cluster' (cluster.db) - queue:work then failed every run with
    // "no such table: queue_jobs" since it reads from the group that was
    // never actually migrated. Workaround needing no vendor changes:
    // temporarily point database.default AT cluster.db, run the
    // migration against the (temporarily real) default connection, then
    // always restore the original value - even on failure, so a crash
    // here can never leave .env pointed at the wrong database.
    private function runQueueMigrationAgainstClusterGroup(): void
    {
        $envPath = ROOTPATH . '.env';
        $env     = file_get_contents($envPath);

        if (! preg_match('/^\s*database\.default\.database\s*=\s*(.*)$/m', $env, $m)) {
            CLI::error('database.default.database not found in .env - cannot redirect the Queue migration.');
            exit(1);
        }
        if (! preg_match('/^\s*database\.cluster\.database\s*=\s*(.*)$/m', $env, $mCluster)) {
            CLI::error('database.cluster.database not found in .env - cannot redirect the Queue migration.');
            exit(1);
        }
        $originalLine = $m[0];
        $clusterValue = trim($mCluster[1]);

        $swapped = preg_replace('/^\s*database\.default\.database\s*=.*$/m', 'database.default.database = ' . $clusterValue, $env);
        file_put_contents($envPath, $swapped);

        // WRITEPATH, not ROOTPATH - CI4's own SQLite3 driver only treats a
        // bare filename with no '/' in it (exactly what ensureDatabaseEnv()
        // writes, "cluster.db") as relative to WRITEPATH ('<root>/writable/'),
        // never the app root itself. Found live 2026-08-20: using ROOTPATH
        // here pointed at a file that never existed, so
        // bootstrapLegacySqliteQueuePriority() silently no-op'd via its own
        // is_file() guard - looked like nothing was wrong, just never fired.
        $clusterDbPath = (str_starts_with($clusterValue, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $clusterValue) === 1)
            ? $clusterValue
            : WRITEPATH . $clusterValue;

        try {
            $this->runMigration('CodeIgniter\Queue');
            if ($this->bootstrapLegacySqliteQueuePriority($clusterDbPath)) {
                // AddPriorityField crashed before it could run (see that
                // method's own docblock), which means `migrate` stopped
                // right there - anything queued AFTER it (e.g.
                // ChangePayloadFieldTypeInSqlsrv) never got a chance to
                // run either. Re-running now that AddPriorityField is
                // marked applied lets the rest of the batch proceed.
                $this->runMigration('CodeIgniter\Queue');
            }
        } finally {
            $restored = preg_replace('/^\s*database\.default\.database\s*=.*$/m', $originalLine, file_get_contents($envPath));
            file_put_contents($envPath, $restored);
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
    // (email or username) - see App\Controllers\AuthController::loginAction() -
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
}
