# Asyncron

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Unofficial package](https://img.shields.io/badge/status-unofficial-orange.svg)](#)

A [CodeIgniter 4](https://codeigniter.com/) library: multi-master file/database/session
replication across nodes (`AdGo\Cluster\`), plus a Dashboard/Settings/Users admin UI to run and
watch it (`AdGo\Cluster\UI\`). `composer require` onto a plain framework + shield + settings +
tasks + queue install.

*Unofficial - not affiliated with, or endorsed by, the CodeIgniter Foundation.*

**The library is `src/` (`composer.json`'s own `autoload` maps `AdGo\Cluster\`/`AdGo\Cluster\UI\`
straight to it) plus the static files in `assets/`, published into a consuming app's own
`public/assets/` at install time.** Everything else at the repo root (`app/`, `public/`, `spark`,
`tests/`, `phpunit.dist.xml`) is a local dev/test harness only (`autoload-dev`, never shipped to a
consumer) - a full CodeIgniter skeleton this package can run and test standalone against, since the
framework has no headless test mode without one.

## Install

```console
composer create-project codeigniter4/appstarter my-app --no-interaction
cd my-app
composer require codeigniter4/shield codeigniter4/settings codeigniter4/tasks codeigniter4/queue
composer config repositories.ad-go-asyncron vcs https://github.com/ad-go/Asyncron
composer require ad-go/asyncron
php spark app:install
```

`php spark app:install` generates `.env`, runs every migration, and seeds two accounts:

- superadmin - `admin@local.host` / `admin1234`
- regular user - `user@local.host` / `user1234`

Change both passwords from the Profile page before this goes anywhere near the public internet.
Safe to re-run - see `src/UI/Commands/InstallCommand.php`.

Copy this package's own ready-made `app/Config/*.php` files (`src/UI/Templates/AppConfig/`) over
the stock ones, and add to `app/Config/Routes.php`:

```php
\AdGo\Cluster\UI\RouteRegistrar::register($routes);
service('auth')->routes($routes);
if (class_exists(\AdGo\Cluster\RouteRegistrar::class)) {
    \AdGo\Cluster\RouteRegistrar::register($routes);
}
```

`asyncron.php` (this project's own deploy tooling) automates every step above end to end.

## Running it

- Webserver document root: `public/`.
- Cron, once a minute:
  `* * * * * cd /path/to/my-app && php spark tasks:run >> writable/logs/tasks_cron.log 2>&1`
  (covers file/DB sync, NAT pulling, and the outbound queue worker in one line).
- Clustering is opt-in - a single install works standalone with no peers configured.

## Adding a node to the cluster

Settings → Cluster card, superadmin only: add each peer by name, URL, and its file-sync
(FTP/FTPS/SSH/SCP) + database credentials. Once two nodes have real, reachable credentials,
pairing - RSA-2048 keypair generation and a handshake exchanging public keys - happens
automatically, no manual "activate" step. Or set `cluster.nodes`/`thisNode`/`secretToken`/
`signingPrivateKey` directly in `.env` on each node - see `src/Config/Cluster.php`'s own docblocks
for the exact shape.

## How it works

- **public** nodes have a real HTTPS URL and get pushed to directly. **nat** nodes pull instead,
  on a schedule (`cluster:pull`/`cluster:long-poll`), since nothing can reach them directly.
- File sync (`cluster:sync-files`) and database sync (`cluster:sync-db`, last-write-wins by
  natural key) both ride that same public-push/NAT-pull split.
- A password change or logout invalidates that email's sessions cluster-wide; a short-lived signed
  ticket lets an already-logged-in user land on another node still logged in.
- Every peer-to-peer call carries a signed `Authorization` header (RSA-2048/SHA256), falling back
  to a legacy shared secret only until every peer has a public key on file.
- The Settings page shows live per-node health (file sync, reachability, DB sync, queue), lets you
  test or restart connections, restore an archived file-conflict version, and toggle settings-sync
  per node.

See `src/` (`AdGo\Cluster\`, the replication engine) and `src/UI/` (`AdGo\Cluster\UI\`, the admin
UI) - every class/method's own docblock is the authoritative reference, not a separate doc that can
drift out of sync with the code.

## Client-side node picker

`/healthz` and `/server-list.json` (both unauthenticated) back a shared racing script
(`public/assets/node-picker.js`):

- `/` itself races every known node against `/healthz` and shows this node's own name, the current
  session's connection status, and every peer's live status - no forced redirect, since this is the
  permanent home page, not a transient picker.
- The login page shows a dismissible "a faster node is answering right now" banner instead of
  redirecting away from a form you may already be filling in.

## Not built yet

- Dashboard "synced" status reflects this node's own manifest, not a confirmed per-peer delivery
  receipt.
- NAT test-connection latency is bounded by that node's own pull cadence (up to ~1 minute).

## What's inside

[CodeIgniter 4](https://github.com/codeigniter4/CodeIgniter4),
[Shield](https://github.com/codeigniter4/shield),
[Settings](https://github.com/codeigniter4/settings),
[Tasks](https://github.com/codeigniter4/tasks),
[Queue](https://github.com/codeigniter4/queue),
[phpseclib](https://github.com/phpseclib/phpseclib) (SSH checks),
[Tabler](https://tabler.io/) (UI), [flag-icons](https://github.com/lipis/flag-icons),
[Apache ECharts](https://echarts.apache.org/) (Dashboard graph).

## License

MIT - see [LICENSE](LICENSE).
