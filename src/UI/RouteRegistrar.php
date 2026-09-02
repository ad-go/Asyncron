<?php

declare(strict_types=1);

namespace AdGo\Cluster\UI;

use CodeIgniter\Router\RouteCollection;

/**
 * Deliberately NOT named src/UI/Config/Routes.php - same collision reason
 * as AdGo\Cluster\RouteRegistrar's own docblock (CI4's `spark routes`
 * command scans every autoloaded namespace for a file at exactly that
 * path and `require`s it directly, on top of normal autoloading, causing
 * a "Cannot declare class" redeclaration).
 *
 * Not auto-registered either - same deliberate choice as AdGo\Cluster\
 * RouteRegistrar (a required package shouldn't add routes to a host app
 * without being asked to). Add one line to app/Config/Routes.php:
 *
 *   \AdGo\Cluster\UI\RouteRegistrar::register($routes);
 *
 * All the Dashboard/Settings/Users/Profile/Auth/Locale routes this app's
 * admin UI needs - moved here verbatim from this app's own
 * app/Config/Routes.php now that this package is `composer require`'d
 * onto a plain appstarter skeleton instead of being the project root
 * itself. Shield's own login/logout routes are NOT here - those stay in
 * the host's Routes.php as `service('auth')->routes($routes);`, Shield's
 * own established convention.
 */
class RouteRegistrar
{
    // Shared by /server-list.json, the login page's own "faster node?"
    // banner, and the host app's own Home::index() (see asyncron.php's
    // Home.php/index.php templates) - one place computing "this node plus
    // every reachable public peer" instead of copies drifting apart. See
    // the /server-list.json route below for why 'nat' peers are excluded
    // and why this node's own baseURL (not itself a member of Config\
    // Cluster::$nodes, which holds PEERS only) is added back in.
    public static function servers(): array
    {
        $servers = [];
        $self    = rtrim((string) config('App')->baseURL, '/');
        if ($self !== '') {
            $servers[] = $self;
        }
        foreach (config('Cluster')->nodes as $node) {
            if (($node['type'] ?? 'public') !== 'public') {
                continue;
            }
            $servers[] = rtrim((string) ($node['baseURL'] ?? ''), '/');
        }

        return array_values(array_unique(array_filter($servers)));
    }

    // Shared by every fix-* self-service repair/diagnostic route below -
    // all six repeated this identical 3-line guard verbatim before this
    // extraction. Returns a 403 response to return-and-short-circuit on,
    // or null when the caller may proceed - `if ($denied = self::
    // requireSuperadmin()) { return $denied; }` at the top of each closure.
    private static function requireSuperadmin(): ?\CodeIgniter\HTTP\ResponseInterface
    {
        if (! auth()->loggedIn() || ! (auth()->user()?->inGroup('superadmin'))) {
            return service('response')->setStatusCode(403)->setBody('Superadmin login required.');
        }

        return null;
    }

    public static function register(RouteCollection $routes): void
    {
        // Leading backslash required on every controller reference below -
        // same reason as AdGo\Cluster\RouteRegistrar::register()'s own
        // comment: without it CI4's router silently prepends the app's
        // own default controller namespace (App\Controllers\...) instead
        // of this package's actual fully-qualified one.

        // Unauthenticated on purpose - polled by client-side "pick the
        // fastest node" tooling (see asyncron.md) that has no credentials
        // and just needs a fast yes/no on reachability, not app state. A
        // closure instead of a controller so this never touches the
        // 'session' filter or Shield/DB at all - the one route in this app
        // that must still answer while the database itself is down.
        $routes->get('healthz', static fn () => service('response')->setStatusCode(200)->setBody('ok'));

        // Also unauthenticated, same picker consumer as healthz above - a
        // browser fetches this from whichever node it just connected to,
        // with no session/credentials of its own to send. Only Config\
        // Cluster::$nodes' 'public' entries (a real HTTPS baseURL a
        // browser can reach directly) go in the list - a 'nat' peer is
        // only ever reachable node-to-node via outbound Pull, never from
        // outside, so listing it here would just be a dead entry the
        // picker's own probe always fails. This node's own baseURL is
        // added too (it isn't a member of its own $nodes, which holds
        // PEERS only) so the picker's list stays complete even starting
        // from a single node.
        // Unauthenticated, same reasoning as healthz above, plus a
        // structural one specific to this route: fixing a wrong
        // app.baseURL currently means redeploying install.php, which on
        // at least one real node (h1q) can only ever land in public/ by
        // renaming the existing directory aside first (see install.ps1's
        // own Send-SshFiles comment on the ownership split that forces
        // this) - safe only immediately before a full wipe+reinstall
        // rebuilds public/ anyway, actively destructive against a node
        // that's already serving correctly and just has a stale baseURL
        // (found live 2026-09-02: doing exactly that turned a working
        // node briefly unreachable for no reason). Reachable through the
        // app's OWN already-deployed public/index.php instead, this needs
        // no separate deploy step at all - just requesting the URL fixes
        // it, on any node, any time. Derives baseURL from the CURRENT
        // request the exact same way install.php's own asyncronDetectBaseUrl()
        // does (Host header + scheme, X-Forwarded-Proto override) -
        // trusting the Host header is an existing, already-accepted
        // precedent here, not a new exposure this route introduces.
        $routes->get('fix-baseurl', static function () {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if ($host === '') {
                return service('response')->setStatusCode(400)->setBody('No Host header on this request - cannot derive a baseURL.');
            }

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
                $scheme = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
            }
            $baseUrl = "$scheme://$host/";

            $envPath = ROOTPATH . '.env';
            $fp      = fopen($envPath, 'c+');
            if ($fp === false) {
                return service('response')->setStatusCode(500)->setBody("Could not open $envPath");
            }
            flock($fp, LOCK_EX);
            $lines = explode("\n", rtrim((string) stream_get_contents($fp), "\n"));
            $found = false;
            foreach ($lines as $i => $line) {
                if (preg_match('/^\s*app\.baseURL\s*=/', $line)) {
                    $lines[$i] = "app.baseURL = '$baseUrl'";
                    $found      = true;
                }
            }
            if (!$found) {
                $lines[] = "app.baseURL = '$baseUrl'";
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, implode("\n", $lines) . "\n");
            flock($fp, LOCK_UN);
            fclose($fp);

            return service('response')->setStatusCode(200)->setBody("app.baseURL set to $baseUrl\n");
        });

        // Session-gated (superadmin), unlike healthz/fix-baseurl/server-list.json
        // above - this writes into cluster.nodes' trust data, not just a
        // cosmetic/informational value. Found live 2026-09-02: a node that
        // already had its OWN signingPrivateKey (carried across reinstalls
        // by asyncron.php's own cluster-identity preserve step - see that
        // script's own comment) got a BLANK publicKey for its own registry
        // entry anyway, because addNode() always initializes a freshly
        // added entry's publicKey to '' regardless of whether a keypair
        // already exists - there's no path that reconciles the two. Every
        // outgoing call then signed with the real private key, which every
        // peer rejected (Cluster::verifyAuthHeader() has no public key on
        // file to check it against), while autoStartCluster()'s own
        // "reuse this node's identity" branch kept re-sending that SAME
        // blank publicKey on every retry, since it trusts the registry
        // entry rather than re-deriving from the key it already holds.
        // This is the one-time reconciliation: derive the public half from
        // the private key this node already has (never transmits or
        // exposes the private key itself) and write it into this node's
        // own entry, self-healing exactly that gap without generating a
        // new keypair (which would just orphan any peer that already
        // trusted the old public key).
        $routes->get('fix-cluster-publickey', static function () {
            if ($denied = self::requireSuperadmin()) {
                return $denied;
            }
            if (! class_exists(\AdGo\Cluster\Cluster::class)) {
                return service('response')->setStatusCode(503)->setBody('ad-go/cluster is not installed.');
            }

            $clusterConfig = config('Cluster');
            $privateKeyB64 = $clusterConfig->signingPrivateKey;
            $thisNode      = $clusterConfig->thisNode;
            if ($privateKeyB64 === '' || $thisNode === '') {
                return service('response')->setStatusCode(422)->setBody('This node has no signingPrivateKey/thisNode configured yet - nothing to derive.');
            }

            $privateKeyPem = base64_decode($privateKeyB64, true);
            $privateKey    = $privateKeyPem !== false ? openssl_pkey_get_private($privateKeyPem) : false;
            $details       = $privateKey !== false ? openssl_pkey_get_details($privateKey) : false;
            if ($details === false || ! isset($details['key'])) {
                return service('response')->setStatusCode(500)->setBody('Could not derive a public key from the configured signingPrivateKey.');
            }
            $derivedPublicKeyB64 = base64_encode($details['key']);

            $cluster = new \AdGo\Cluster\Cluster();
            $entries = $cluster->allNodes();
            if (! array_key_exists($thisNode, $entries)) {
                return service('response')->setStatusCode(422)->setBody("This node's own name ('$thisNode') has no registry entry to update - add it via Settings first.");
            }
            if (($entries[$thisNode]['publicKey'] ?? '') === $derivedPublicKeyB64) {
                return service('response')->setStatusCode(200)->setBody("Already up to date - $thisNode's own publicKey already matches its signingPrivateKey.\n");
            }

            $entries[$thisNode]['publicKey'] = $derivedPublicKeyB64;
            if (! \AdGo\Cluster\ClusterEnvWriter::writeNodes($entries)) {
                return service('response')->setStatusCode(500)->setBody('Could not write .env.');
            }

            return service('response')->setStatusCode(200)->setBody("$thisNode's own publicKey set to match its existing signingPrivateKey.\n");
        }, ['filter' => 'session']);

        // Clears ONE peer's stale publicKey locally, so it can re-pair via
        // cluster/bootstrap-handshake's own "already paired" refusal (see
        // that method's own docblock) without the collateral damage found
        // live 2026-09-03: SettingsController::deleteNode() is the only
        // other way to blank a peer's key today, but it ALSO calls
        // Cluster::broadcastNodeDelete(), which tells every OTHER peer to
        // delete THEIR OWN copy of that same node - repairing node1's
        // stale key on node2 this way silently wiped node3's own,
        // perfectly good, independent pairing with node1 as a side
        // effect. This writes .env directly, exactly like updateNode()
        // does for its own whitelisted fields, and broadcasts nothing.
        $routes->get('fix-reset-peer-key', static function () {
            if ($denied = self::requireSuperadmin()) {
                return $denied;
            }
            if (! class_exists(\AdGo\Cluster\Cluster::class)) {
                return service('response')->setStatusCode(503)->setBody('ad-go/cluster is not installed.');
            }

            $peer = (string) service('request')->getGet('peer');
            if ($peer === '' || preg_match('/^[a-zA-Z0-9_-]+$/', $peer) !== 1) {
                return service('response')->setStatusCode(422)->setBody("Usage: ?peer=<node name>\n");
            }

            $cluster = new \AdGo\Cluster\Cluster();
            $entries = $cluster->allNodes();
            if (! array_key_exists($peer, $entries)) {
                return service('response')->setStatusCode(422)->setBody("Unknown peer '$peer'.\n");
            }
            if ($peer === $cluster->thisNodeName()) {
                return service('response')->setStatusCode(422)->setBody("Refusing to reset this node's own key - that would break its own outgoing signing, not just this one pairing.\n");
            }

            $entries[$peer]['publicKey'] = '';
            if (! \AdGo\Cluster\ClusterEnvWriter::writeNodes($entries)) {
                return service('response')->setStatusCode(500)->setBody('Could not write .env.');
            }

            return service('response')->setStatusCode(200)->setBody("'$peer's stale publicKey cleared locally - it can now re-pair via bootstrap-handshake.\n");
        }, ['filter' => 'session']);

        // Self-service equivalent of `spark queue:retry` - found live
        // 2026-09-02: a peer that's down for a while accumulates failed
        // cluster-files jobs (PushFileJob's own retry budget exhausted),
        // and peerHealthStatuses()'s own docblock deliberately makes ANY
        // queue_jobs_failed row for that peer mark it 'bad' forever, even
        // after the peer comes back and every NEW sync succeeds - a stuck
        // job is a real thing to surface, not something a later success
        // should silently paper over. Once the peer is actually confirmed
        // back (Settings' "Test" button both directions), this is how an
        // admin clears the backlog without CLI/SSH access: re-pushes every
        // failed job onto its original queue and removes it from
        // queue_jobs_failed (BaseHandler::retry()'s own behavior, the
        // exact thing `spark queue:retry` calls) - a real retry, not a
        // silent delete, so a job that fails again just lands right back
        // here.
        $routes->get('fix-retry-failed-queue', static function () {
            if ($denied = self::requireSuperadmin()) {
                return $denied;
            }

            $count = service('queue')->retry(null, null);

            return service('response')->setStatusCode(200)->setBody("Retried $count failed job(s).\n");
        }, ['filter' => 'session']);

        // Manual catch-up for whatever `* * * * * php spark tasks:run` (this
        // project's own crontab entry, kept in sync by hand per node - not
        // managed by this script - see README "Running it") would have done
        // on its own next tick - file/DB sync, NAT pulling, the outbound
        // queue worker, all in one call (Config\Tasks' own schedule). Found
        // live 2026-09-02: needed alongside fix-retry-failed-queue above to
        // actually SEE a peer's just-retried jobs go through immediately,
        // rather than waiting on (or debugging, with no shell access) that
        // node's own crontab.
        $routes->get('fix-run-tasks', static function () {
            if ($denied = self::requireSuperadmin()) {
                return $denied;
            }

            ob_start();
            command('tasks:run');
            $output = ob_get_clean();

            return service('response')->setStatusCode(200)->setBody($output !== '' ? $output : "tasks:run completed with no output.\n");
        }, ['filter' => 'session']);

        // Read-only companion to fix-retry-failed-queue above - that route
        // says HOW MANY jobs failed, never WHY, and there's no admin UI or
        // shell access here to read queue_jobs_failed's own 'exceptions'
        // column directly. Newest-first, capped at 5: enough to diagnose a
        // recurring failure without dumping an unbounded backlog into one
        // response.
        $routes->get('fix-show-failed-queue', static function () {
            if ($denied = self::requireSuperadmin()) {
                return $denied;
            }

            $rows = model(\CodeIgniter\Queue\Models\QueueJobFailedModel::class)
                ->orderBy('id', 'DESC')
                ->findAll(5);

            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'id'      => $row->id,
                    'queue'   => $row->queue,
                    'job'     => $row->payload['job'] ?? null,
                    'data'    => $row->payload['data'] ?? null,
                    'error'   => $row->exceptions,
                ];
            }

            return service('response')->setStatusCode(200)->setJSON($out);
        }, ['filter' => 'session']);

        // queue_jobs_failed's own 'exceptions' column came back empty on
        // every job checked live 2026-09-02 (codeigniter4/queue populates
        // it from BaseHandler::logFailed(), a DIFFERENT path than this
        // package's own SyncDbRowJob/PushFileJob, which record their own
        // human-readable error into DbSyncLog/writable/Cluster/db_sync_log.json
        // instead - see those classes' own catch blocks). This reads THAT
        // log directly since it's the one with the actual message.
        $routes->get('fix-show-sync-errors', static function () {
            if ($denied = self::requireSuperadmin()) {
                return $denied;
            }
            if (! class_exists(\AdGo\Cluster\DbSyncLog::class)) {
                return service('response')->setStatusCode(503)->setBody('ad-go/cluster is not installed.');
            }

            $entries = array_slice(array_reverse((new \AdGo\Cluster\DbSyncLog())->all()), 0, 10);

            return service('response')->setStatusCode(200)->setJSON($entries);
        }, ['filter' => 'session']);

        $routes->get('server-list.json', static function () {
            return service('response')
                ->setStatusCode(200)
                ->setContentType('application/json')
                ->setBody(json_encode(['servers' => self::servers()], JSON_UNESCAPED_SLASHES));
        });

        // '/' itself is deliberately NOT registered here - it belongs to
        // the host app's own app/Controllers/Home.php (see asyncron.php's
        // own Home.php/index.php templates), an unauthenticated landing
        // page showing this node's own name, the current session's
        // connection info, and every cluster peer's live status (ported
        // from C:\ai\server-picker via public/assets/node-picker.js, the
        // same script the login page's own "faster node" banner already
        // shares - see that route's own comment). Dashboard moves to the
        // literal 'dashboard' URI instead - url_to('dashboard') resolves
        // through this route's own NAME, so nothing else in this app
        // needed to change when it stopped owning '/'.
        $routes->get('dashboard', '\AdGo\Cluster\UI\Controllers\Dashboard::index', ['as' => 'dashboard', 'filter' => 'session']);
        $routes->get('dashboard/network-status', '\AdGo\Cluster\UI\Controllers\Dashboard::networkStatus', ['filter' => 'session']);
        $routes->post('dashboard/restore-conflict', '\AdGo\Cluster\UI\Controllers\Dashboard::restoreConflict', ['as' => 'dashboard.restoreConflict', 'filter' => 'session']);
        $routes->post('locale', '\AdGo\Cluster\UI\Controllers\LocaleController::update', ['as' => 'locale.update']);
        $routes->get('login', '\AdGo\Cluster\UI\Controllers\AuthController::loginView', ['as' => 'login']);
        $routes->post('login', '\AdGo\Cluster\UI\Controllers\AuthController::loginAction');
        $routes->get('logout', '\AdGo\Cluster\UI\Controllers\AuthController::logoutView', ['filter' => 'session']);
        $routes->post('logout', '\AdGo\Cluster\UI\Controllers\AuthController::logoutAction', ['as' => 'logout', 'filter' => 'session']);

        $routes->get('profile', '\AdGo\Cluster\UI\Controllers\ProfileController::index', ['as' => 'profile', 'filter' => 'session']);
        $routes->post('profile', '\AdGo\Cluster\UI\Controllers\ProfileController::updateField', ['as' => 'profile.updateField', 'filter' => 'session']);
        $routes->post('profile/preference', '\AdGo\Cluster\UI\Controllers\ProfileController::preference', ['filter' => 'session']);
        $routes->post('profile/avatar', '\AdGo\Cluster\UI\Controllers\ProfileController::uploadAvatar', ['as' => 'profile.uploadAvatar', 'filter' => 'session']);
        $routes->post('profile/avatar/delete', '\AdGo\Cluster\UI\Controllers\ProfileController::deleteAvatar', ['as' => 'profile.deleteAvatar', 'filter' => 'session']);

        $routes->get('settings', '\AdGo\Cluster\UI\Controllers\SettingsController::index', ['as' => 'settings', 'filter' => 'session']);
        $routes->post('settings', '\AdGo\Cluster\UI\Controllers\SettingsController::update', ['as' => 'settings.update', 'filter' => 'session']);
        $routes->post('settings/nodes', '\AdGo\Cluster\UI\Controllers\SettingsController::updateNode', ['as' => 'settings.updateNode', 'filter' => 'session']);
        $routes->post('settings/nodes/test', '\AdGo\Cluster\UI\Controllers\SettingsController::testNode', ['as' => 'settings.testNode', 'filter' => 'session']);
        $routes->get('settings/test-result', '\AdGo\Cluster\UI\Controllers\SettingsController::testResult', ['as' => 'settings.testResult', 'filter' => 'session']);
        $routes->get('settings/node-status', '\AdGo\Cluster\UI\Controllers\SettingsController::nodeStatus', ['as' => 'settings.nodeStatus', 'filter' => 'session']);
        $routes->get('settings/cluster-identity', '\AdGo\Cluster\UI\Controllers\SettingsController::clusterIdentity', ['filter' => 'session']);
        $routes->post('settings/settings-sync', '\AdGo\Cluster\UI\Controllers\SettingsController::updateSettingsSync', ['as' => 'settings.updateSettingsSync', 'filter' => 'session']);
        $routes->post('settings/production-sync', '\AdGo\Cluster\UI\Controllers\SettingsController::updateProductionSync', ['as' => 'settings.updateProductionSync', 'filter' => 'session']);
        $routes->post('settings/cluster-restart', '\AdGo\Cluster\UI\Controllers\SettingsController::restartCluster', ['as' => 'settings.restartCluster', 'filter' => 'session']);
        $routes->post('settings/fix-writable-permissions', '\AdGo\Cluster\UI\Controllers\SettingsController::fixWritablePermissions', ['filter' => 'session']);
        $routes->post('settings/databases', '\AdGo\Cluster\UI\Controllers\SettingsController::updateDatabase', ['as' => 'settings.updateDatabase', 'filter' => 'session']);
        $routes->post('settings/logo', '\AdGo\Cluster\UI\Controllers\SettingsController::uploadLogo', ['as' => 'settings.uploadLogo', 'filter' => 'session']);
        $routes->post('settings/logo/delete', '\AdGo\Cluster\UI\Controllers\SettingsController::deleteLogo', ['as' => 'settings.deleteLogo', 'filter' => 'session']);
        $routes->post('settings/export', '\AdGo\Cluster\UI\Controllers\SettingsController::exportSettings', ['as' => 'settings.exportSettings', 'filter' => 'session']);
        $routes->post('settings/import', '\AdGo\Cluster\UI\Controllers\SettingsController::importSettings', ['as' => 'settings.importSettings', 'filter' => 'session']);
        $routes->post('settings/cluster-import', '\AdGo\Cluster\UI\Controllers\SettingsController::importCluster', ['as' => 'settings.importCluster', 'filter' => 'session']);
        $routes->post('settings/nodes/add', '\AdGo\Cluster\UI\Controllers\SettingsController::addNode', ['as' => 'settings.addNode', 'filter' => 'session']);
        $routes->post('settings/nodes/delete', '\AdGo\Cluster\UI\Controllers\SettingsController::deleteNode', ['as' => 'settings.deleteNode', 'filter' => 'session']);
        $routes->post('settings/cluster-reset', '\AdGo\Cluster\UI\Controllers\SettingsController::resetCluster', ['as' => 'settings.resetCluster', 'filter' => 'session']);
        // Peer-to-peer, NOT a logged-in browser - deliberately UNFILTERED
        // (no 'session', and NOT 'cluster-auth' either - see
        // SettingsController::clusterHandshake()'s own docblock for why
        // that filter can't apply to the one call that first establishes
        // what it verifies). Also added to app/Config/Filters.php's own
        // $globals['before']['csrf'] except-list, same as every other
        // server-to-server cluster/* route there (a route having no
        // 'filter' here does NOT exempt it from that separate GLOBAL
        // filter list).
        $routes->post('cluster/bootstrap-handshake', '\AdGo\Cluster\UI\Controllers\SettingsController::clusterHandshake');

        $routes->get('users', '\AdGo\Cluster\UI\Controllers\UsersController::index', ['as' => 'users', 'filter' => 'session']);
        $routes->get('users/list', '\AdGo\Cluster\UI\Controllers\UsersController::list', ['as' => 'users.list', 'filter' => 'session']);
        $routes->get('users/(:num)', '\AdGo\Cluster\UI\Controllers\UsersController::show/$1', ['filter' => 'session']);
        $routes->post('users', '\AdGo\Cluster\UI\Controllers\UsersController::create', ['filter' => 'session']);
        $routes->post('users/(:num)', '\AdGo\Cluster\UI\Controllers\UsersController::update/$1', ['filter' => 'session']);
        $routes->delete('users/(:num)', '\AdGo\Cluster\UI\Controllers\UsersController::delete/$1', ['filter' => 'session']);
        $routes->post('users/(:num)/ban', '\AdGo\Cluster\UI\Controllers\UsersController::ban/$1', ['filter' => 'session']);
        $routes->post('users/(:num)/unban', '\AdGo\Cluster\UI\Controllers\UsersController::unban/$1', ['filter' => 'session']);
    }
}
