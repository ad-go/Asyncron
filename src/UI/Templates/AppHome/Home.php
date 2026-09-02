<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Replaces codeigniter4/appstarter's own stock Home controller - see
 * asyncron.php's own install-time copy of this file (and its deletion of
 * the stock app/Views/welcome_message.php it used to render) for how this
 * gets here. '/' itself is registered by appstarter's own untouched
 * Routes.php line (`$routes->get('/', 'Home::index');`) - AdGo\Cluster\UI\
 * RouteRegistrar deliberately does NOT claim '/' (Dashboard moved to the
 * literal 'dashboard' URI instead - see that class's own comment), so
 * this is the one thing standing between a fresh install and a bare 404
 * at the site root.
 *
 * Deliberately thin, the same three things for every visitor: this
 * node's own identity, the CURRENT session's connection info (never a
 * re-implementation of the superadmin-only, post-login Dashboard), and
 * the cluster's own node list - live status filled in client-side (see
 * index.php's own script), the same way C:\ai\server-picker's standalone
 * page already worked, ported here via public/assets/node-picker.js so
 * this app never needs that separate hosting again.
 */
class Home extends BaseController
{
    public function index(): string
    {
        $servers = class_exists(\AdGo\Cluster\UI\RouteRegistrar::class)
            ? \AdGo\Cluster\UI\RouteRegistrar::servers()
            : [];

        // config('Cluster')->thisNode over gethostname() - the internal
        // OS hostname (a hosting account's internal server name) is
        // rarely the identity anyone administering this cluster actually
        // recognizes; falling back to the URL host at least matches what
        // they typed to get here when thisNode isn't configured yet.
        $thisNode = class_exists(\AdGo\Cluster\Cluster::class)
            ? (new \AdGo\Cluster\Cluster())->thisNodeName()
            : '';
        $serverName = $thisNode !== ''
            ? $thisNode
            : ((string) parse_url((string) config('App')->baseURL, PHP_URL_HOST) ?: (string) (gethostname() ?: 'server'));

        $user = function_exists('auth') ? auth()->user() : null;

        return view('index', [
            'serverName' => $serverName,
            'user'       => $user,
            'servers'    => $servers,
        ]);
    }
}
