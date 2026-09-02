<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// This app's own admin UI routes (Dashboard/Settings/Users/Profile/Auth/
// Locale) now live in AdGo\Cluster\UI\RouteRegistrar - moved there once
// this repo's app/ layer became a `composer require`-able library
// (AdGo\Cluster\UI\) instead of the project root itself. This exact line
// is what a FRESH host app (framework + shield + settings + tasks + queue
// + this package) needs to add here too - see README's install section.
\AdGo\Cluster\UI\RouteRegistrar::register($routes);

service('auth')->routes($routes);

// ad-go/cluster (the sync engine, AdGo\Cluster\ minus \UI\) is a
// separate install step from ad-go/asyncron's own UI routes above - a
// fresh install must not 500 every request just because it hasn't been
// composer-required yet. Guarded so this file works identically before
// and after that package lands. Also found live to be NOT optional in
// practice even once installed - a route registered by hand directly on a
// node (not through this file) is silently wiped the next time cluster-ui
// itself gets re-applied there (CI4post.php overwrites this exact file),
// which is exactly what happened testing this integration the first time.
if (class_exists(\AdGo\Cluster\RouteRegistrar::class)) {
    \AdGo\Cluster\RouteRegistrar::register($routes);
}
