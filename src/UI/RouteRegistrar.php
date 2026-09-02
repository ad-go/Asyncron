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
    public static function register(RouteCollection $routes): void
    {
        // Leading backslash required on every controller reference below -
        // same reason as AdGo\Cluster\RouteRegistrar::register()'s own
        // comment: without it CI4's router silently prepends the app's
        // own default controller namespace (App\Controllers\...) instead
        // of this package's actual fully-qualified one.

        // Deliberately no second literal 'dashboard' route alongside this
        // one - found live 2026-08-20: the two collided on CI4's own
        // implicit route NAME (this route's explicit 'as' => 'dashboard'
        // vs. the second route's auto-derived name, also 'dashboard'),
        // which silently made the literal /dashboard URI 404 rather than
        // erroring at route-registration time. Nothing in this app ever
        // links to that literal path anyway - every internal link uses
        // url_to('dashboard'), which resolves via the NAME (registered
        // here) to this route's own URI ('/'), never a hardcoded
        // '/dashboard' string.
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
        $routes->get('server-list.json', static function () {
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
            $servers = array_values(array_unique(array_filter($servers)));

            return service('response')
                ->setStatusCode(200)
                ->setContentType('application/json')
                ->setBody(json_encode(['servers' => $servers], JSON_UNESCAPED_SLASHES));
        });

        $routes->get('/', '\AdGo\Cluster\UI\Controllers\Dashboard::index', ['as' => 'dashboard', 'filter' => 'session']);
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
