<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Taskbar "Settings" menu: a reactive form with no save button - every
 * field autosaves on change via fetch(), one field per request, straight
 * into the Settings package's own store (config('Settings')/database
 * handler, no dedicated Config class needed for these free-form keys).
 * Superadmin only (see app/Views/Layout/app.php taskbar guard).
 */
class SettingsController extends BaseController
{
    // Matches the [data-bs-theme-primary="..."] variants tabler-themes.min.css
    // ships (see this repo's README - Tabler's CSS/JS isn't bundled here).
    public const THEME_COLORS = ['blue', 'azure', 'indigo', 'purple', 'pink', 'red', 'orange', 'yellow', 'lime', 'green', 'teal', 'cyan'];

    // Per-node fields editable from the Nodes table below. 'name' isn't in
    // here - it's the row key (ad-go/cluster's own node registry), not an
    // editable value. Stored as Settings key 'Nodes.{prop}' with the node
    // name as $context - see nodeRows()'s own docblock.
    //
    // 'protocol' plus TWO independent credential sets, not one - ftp* for
    // the FTP/FTPS family, ssh* for the SSH/SCP family (SCP rides over an
    // SSH connection, same host/port/user/pass either way). Switching
    // 'protocol' between families must never overwrite the OTHER family's
    // stored credentials - a node deployed over FTPS today but reachable
    // over SSH too (h1q, bak, res all are) needs both remembered
    // independently, not the last-selected one clobbering the other.
    public const NODE_PROPS = ['type', 'url', 'protocol', 'ftpHost', 'ftpPort', 'ftpUser', 'ftpPass', 'sshHost', 'sshPort', 'sshUser', 'sshPass'];
    // 'local' - a node reachable via the local filesystem, not a network
    // protocol at all (e.g. a second install sharing this same disk).
    // Pairs with the 'LOCAL' protocol below - settings.js auto-selects it
    // the moment a row's Type changes to 'local', since none of the other
    // protocols mean anything without a real network endpoint to reach.
    public const NODE_TYPES = ['nat', 'public', 'local'];
    public const NODE_PROTOCOLS = ['FTP', 'FTPS explicit (AUTH TLS)', 'SSH', 'SCP', 'LOCAL'];

    // Per-node Databases table, same swap-on-select shape as Nodes' FTP/SSH
    // credential families above, generalized to FIVE independent sets
    // instead of two - one per CI4-supported driver, not just the driver
    // this app itself happens to run. Each node can be reached (for ad-hoc
    // admin/inspection, not this app's own runtime connection) via any of
    // these; switching the Type dropdown swaps which credential set the
    // Host/Port/User/Pass/Database columns show and re-saves them, exactly
    // like Nodes' protocol swap, just across 5 families instead of 2.
    // Database name IS its own field (added 2026-08-19 - some of this
    // project's real nodes reuse a pre-existing, differently-named database
    // rather than one literally called after the CI4 connection group, e.g.
    // upz's 'production' group actually points at its pre-existing D10beta
    // database - the credential set alone doesn't capture that).
    public const DATABASE_TYPES = ['mysql', 'postgres', 'sqlite3', 'oci8', 'sqlsrv'];
    public const DATABASE_PROPS = [
        'type',
        'mysqlHost', 'mysqlPort', 'mysqlUser', 'mysqlPass', 'mysqlDatabase',
        'postgresHost', 'postgresPort', 'postgresUser', 'postgresPass', 'postgresDatabase',
        'sqlite3Host', 'sqlite3Port', 'sqlite3User', 'sqlite3Pass', 'sqlite3Database',
        'oci8Host', 'oci8Port', 'oci8User', 'oci8Pass', 'oci8Database',
        'sqlsrvHost', 'sqlsrvPort', 'sqlsrvUser', 'sqlsrvPass', 'sqlsrvDatabase',
    ];

    public function index(): string
    {
        if (! (auth()->user()?->inGroup('superadmin'))) {
            return redirect()->to('dashboard');
        }

        // Checked on every load (not just after add/import) - see this
        // method's own docblock for why "first load with 2 valid nodes" is
        // as good a trigger as any explicit action here. Runs BEFORE the
        // rest of this method reads the registry/identity below, so a page
        // load that itself completes the bootstrap reflects the new
        // functional state immediately, not one reload later.
        $this->autoStartCluster();

        $cluster  = class_exists(\AdGo\Cluster\Cluster::class) ? new \AdGo\Cluster\Cluster() : null;
        $nodes    = $this->nodeRows();
        $thisNode = $cluster?->thisNodeName();
        $thisNode = ($thisNode === '' ? null : $thisNode);
        // At least one PEER's publicKey actually on file, not just this
        // node's own identity - autoStartCluster() can leave a node with
        // thisNode+secretToken set but no completed handshake yet (its
        // peer was briefly unreachable, say), which is still "not really
        // functional" no matter how it got that way.
        $hasPeerKey = false;
        if ($thisNode !== null) {
            foreach ($cluster->allNodes() as $name => $node) {
                if ($name !== $thisNode && ($node['publicKey'] ?? '') !== '') {
                    $hasPeerKey = true;
                    break;
                }
            }
        }

        return view('Settings/index', [
            'siteTitle'  => setting('Site.title'),
            'siteFooter' => setting('Site.footer'),
            'siteLogo'   => setting('Site.logo'),
            // Named siteTheme (not 'theme') so it can't collide with the
            // per-user 'theme' that layoutData() below feeds to
            // Layout/app.php's <html data-bs-theme="...">.
            'siteTheme'  => setting('Site.theme') ?? 'light',
            'siteThemeColor' => setting('Site.themeColor') ?? 'blue',
            'user'       => auth()->user(),
            'nodes'      => $nodes,
            'databases'  => $this->databaseRows(),
            // Which row (if any) is THIS node - the view skips rendering
            // that row's name badge as a test trigger (see testNode()'s
            // own docblock for why testing a connection to yourself is
            // meaningless). Null when cluster.thisNode couldn't be
            // resolved (no ad-go/cluster, or an unconfigured/mismatched
            // .env) - every row's badge just stays clickable in that case.
            'thisNode'   => $thisNode,
            // Gates Export/Delete in the view - "at least 2 real nodes, AND
            // this node actually knows its own identity/secret" (mirrors
            // the exact "configured" notion Cluster::status()/similar
            // already use elsewhere: thisNode + secretToken both set - not
            // a new definition of "functional" invented just for this
            // page). The Import-button choice (bootstrap importCluster()
            // vs the regular per-node importSettings()) is a SEPARATE,
            // simpler check the view makes directly against $nodes === []
            // - importCluster() only ever accepts a fully empty registry
            // (see its own docblock: first-run only, not a merge tool), so
            // it can't share this 2-node threshold.
            'clusterFunctional' => count($nodes) >= 2 && $thisNode !== null && ($cluster?->secretToken() ?? '') !== '' && $hasPeerKey,
            // Only computed when the registry is empty - seeds the
            // add-node row's fields with what's ACTUALLY known about this
            // server (its own app.baseURL, its own live DB connection
            // config) so a fresh install's "+" row starts pre-filled
            // instead of every field blank. Never written anywhere on its
            // own; the admin can freely edit any field (name included)
            // before hitting "+", or ignore it entirely and upload a full
            // topology file instead.
            'selfNode'   => $nodes === [] ? $this->selfNodePreview() : null,
        ] + $this->layoutData());
    }

    // See index()'s own 'selfNode' docblock. DB password deliberately
    // omitted - unlike every other credential on this page, this one is
    // the app's OWN live production secret, not an admin-entered reference
    // value for reaching some OTHER node, and there's no legitimate reason
    // to pre-fill a password field with it (the admin can still type one
    // in themselves before submitting the add-node row).
    private function selfNodePreview(): array
    {
        $url  = rtrim((string) config('App')->baseURL, '/');
        $host = parse_url($url, PHP_URL_HOST);
        $name = (is_string($host) && $host !== '') ? $host : $url;
        // addNode() only accepts letters/digits/'-'/'_' (see its own
        // docblock on why) - a real hostname's dots would otherwise make
        // this "helpful" suggestion fail validation the instant it's
        // submitted unedited, which live testing 2026-08-21 actually hit
        // ("h.1q.ro" rejected outright). Sanitizing here keeps the
        // suggestion submittable as-is while staying visibly derived from
        // the real hostname, not silently truncating to something unrelated.
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '-', $name) ?? $name;

        $db = config('Database')->default ?? [];
        $driverMap = ['MySQLi' => 'mysql', 'Postgre' => 'postgres', 'SQLite3' => 'sqlite3', 'OCI8' => 'oci8', 'SQLSRV' => 'sqlsrv'];
        $dbType    = $driverMap[$db['DBDriver'] ?? ''] ?? 'mysql';

        return [
            'name' => $name,
            'url'  => $url,
            'database' => [
                'type'     => $dbType,
                'host'     => (string) ($db['hostname'] ?? ''),
                'port'     => (string) ($db['port'] ?? ''),
                'user'     => (string) ($db['username'] ?? ''),
                'database' => (string) ($db['database'] ?? ''),
            ],
        ];
    }

    public function update(): ResponseInterface
    {
        if (! (auth()->user()?->inGroup('superadmin'))) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        $allowed = ['title', 'footer', 'theme', 'themeColor'];
        $field   = (string) $this->request->getPost('field');
        $value   = (string) $this->request->getPost('value');
        if (! in_array($field, $allowed, true)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false]);
        }
        // Only the exact palette tabler-themes.min.css ships
        // [data-bs-theme-primary="..."] rules for - anything else silently
        // falls back to Tabler's default blue with no indication why.
        if ($field === 'themeColor' && ! in_array($value, self::THEME_COLORS, true)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false]);
        }
        service('settings')->set('Site.' . $field, $value);

        return $this->response->setJSON(['ok' => true, 'csrf' => $this->csrfPayload()]);
    }

    /**
     * One row per node known to ad-go/cluster's own registry (env
     * 'cluster.nodes' - see Cluster::allNodes()), with any Settings-stored
     * override (type/url/ftp*) layered on top. Nothing is written to the
     * Settings store until a superadmin actually edits a field - this just
     * seeds the table so it isn't blank on first load.
     *
     * Keyed as 'Nodes.{prop}' with the node NAME as the Settings package's
     * own $context parameter (Settings::get()'s 3rd arg) - NOT jammed into
     * the dotted key itself as 'Nodes.{name}.{prop}'. Found live
     * 2026-08-19: codeigniter4/settings' own parseDotSyntax() splits on
     * EVERY dot and prepareClassAndProperty() then keeps only the first
     * two parts (class, property), silently dropping anything after the
     * second dot - 'Nodes.beta.ftpHost' collapsed to class=Nodes,
     * property=beta, so every property write for the SAME node overwrote
     * the exact same row instead of five independent ones. $context is
     * the mechanism this package actually ships for exactly this case.
     *
     * @return array<string, array<string, string>>
     */
    private function nodeRows(): array
    {
        $registry = class_exists(\AdGo\Cluster\Cluster::class) ? (new \AdGo\Cluster\Cluster())->allNodes() : [];
        $settings = service('settings');

        $rows = [];
        foreach ($registry as $name => $node) {
            $protocol     = $settings->get('Nodes.protocol', $name) ?? 'FTP';
            $rows[$name] = [
                // Export-only marker, read by nothing on import (see
                // invalidImportRow()) - 'protocol' below is the field that
                // actually round-trips, this just makes the active
                // connection type visible at a glance without hunting
                // through 11 flat fields, first-child-wins JSON ordering.
                'selected' => $protocol,
                'type'     => $settings->get('Nodes.type', $name) ?? $node['type'],
                'url'      => $settings->get('Nodes.url', $name) ?? $node['baseURL'],
                'protocol' => $protocol,
                'ftpHost'  => $settings->get('Nodes.ftpHost', $name) ?? '',
                'ftpPort'  => $settings->get('Nodes.ftpPort', $name) ?? '',
                'ftpUser'  => $settings->get('Nodes.ftpUser', $name) ?? '',
                'ftpPass'  => $settings->get('Nodes.ftpPass', $name) ?? '',
                'sshHost'  => $settings->get('Nodes.sshHost', $name) ?? '',
                'sshPort'  => $settings->get('Nodes.sshPort', $name) ?? '',
                'sshUser'  => $settings->get('Nodes.sshUser', $name) ?? '',
                'sshPass'  => $settings->get('Nodes.sshPass', $name) ?? '',
            ];
        }

        return $rows;
    }

    /**
     * One row per node, mirroring nodeRows() above exactly (same Settings
     * $context mechanism, same "nothing written until a superadmin edits a
     * field" seeding rule) but for the Databases table - five independent
     * driver-credential sets per node instead of Nodes' two protocol
     * families. 'type' defaults to 'mysql' (the only driver this project
     * actually has real credentials for anywhere - see CI4cluster.asc/upz
     * and h1q's own freshly-provisioned local MariaDB) rather than
     * whatever the node's OWN app.php DBDriver happens to be - this table
     * is an admin credential book for reaching a node's database directly
     * (any of the 5 drivers), not a mirror of the app's own active
     * connection group.
     *
     * @return array<string, array<string, string>>
     */
    private function databaseRows(): array
    {
        $registry = class_exists(\AdGo\Cluster\Cluster::class) ? (new \AdGo\Cluster\Cluster())->allNodes() : [];
        $settings = service('settings');

        $rows = [];
        foreach ($registry as $name => $node) {
            $type = $settings->get('Databases.type', $name) ?? 'mysql';
            // Export-only marker, same reasoning as nodeRows()' own
            // 'selected' - the active driver, visible at a glance instead
            // of hunting through 25 flat credential fields across 5 drivers.
            $row  = ['selected' => $type, 'type' => $type];
            foreach (self::DATABASE_PROPS as $prop) {
                if ($prop === 'type') {
                    continue;
                }
                $row[$prop] = $settings->get('Databases.' . $prop, $name) ?? '';
            }
            $rows[$name] = $row;
        }

        return $rows;
    }

    // Reactive Databases table on the Settings page - same autosave/context
    // shape as updateNode() below, just against DATABASE_PROPS/DATABASE_TYPES.
    public function updateDatabase(): ResponseInterface
    {
        if (! (auth()->user()?->inGroup('superadmin'))) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        $node  = (string) $this->request->getPost('node');
        $prop  = (string) $this->request->getPost('prop');
        $value = (string) $this->request->getPost('value');

        $known = class_exists(\AdGo\Cluster\Cluster::class) ? (new \AdGo\Cluster\Cluster())->allNodes() : [];
        if (! array_key_exists($node, $known) || ! in_array($prop, self::DATABASE_PROPS, true)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false]);
        }
        if ($prop === 'type' && ! in_array($value, self::DATABASE_TYPES, true)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false]);
        }
        service('settings')->set('Databases.' . $prop, $value, $node);

        return $this->response->setJSON(['ok' => true, 'csrf' => $this->csrfPayload()]);
    }

    // Reactive Nodes table on the Settings page - one field per request,
    // same autosave pattern as update() above, just keyed by (node, prop)
    // instead of a flat field name since this is a table, not a form.
    public function updateNode(): ResponseInterface
    {
        if (! (auth()->user()?->inGroup('superadmin'))) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        $node  = (string) $this->request->getPost('node');
        $prop  = (string) $this->request->getPost('prop');
        $value = (string) $this->request->getPost('value');

        $known = class_exists(\AdGo\Cluster\Cluster::class) ? (new \AdGo\Cluster\Cluster())->allNodes() : [];
        if (! array_key_exists($node, $known) || ! in_array($prop, self::NODE_PROPS, true)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false]);
        }
        if ($prop === 'type' && ! in_array($value, self::NODE_TYPES, true)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false]);
        }
        if ($prop === 'protocol' && ! in_array($value, self::NODE_PROTOCOLS, true)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false]);
        }
        // $node is the Settings package's own $context param, not part of
        // the dotted key - see nodeRows()'s own docblock for why.
        service('settings')->set('Nodes.' . $prop, $value, $node);

        return $this->response->setJSON(['ok' => true, 'csrf' => $this->csrfPayload()]);
    }

    // Node-name badge on the Cluster table (not shown for this node's own
    // row - see nodeRows()/the view - testing a connection FROM a node TO
    // itself is meaningless) opens a modal (see settings.js) that calls
    // this. Tests the node's file-sync connection (FTP/FTPS/SSH/SCP) AND
    // its configured database connection together - one click, one
    // requestId, one NAT-queue round trip - rather than the two separate
    // badges/endpoints this page used to have (testNode()/testDatabase(),
    // merged 2026-08-21): a NAT node's own pull cadence is the bottleneck
    // for either test, so splitting them only doubled that wait for no
    // benefit. Gathers both credential sets into the wire shape
    // CapabilityChecker::run('combined', ...) expects, then hands off to
    // dispatchTest() - see that method's own docblock for WHY this can't
    // just call the checkers locally like it used to.
    public function testNode(): ResponseInterface
    {
        if (! (auth()->user()?->inGroup('superadmin'))) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        $node  = (string) $this->request->getPost('node');
        $known = class_exists(\AdGo\Cluster\Cluster::class) ? (new \AdGo\Cluster\Cluster())->allNodes() : [];
        if (! array_key_exists($node, $known)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'Unknown node.']);
        }
        if (! class_exists(\AdGo\Cluster\CapabilityChecker::class)) {
            return $this->response->setStatusCode(503)->setJSON(['ok' => false, 'error' => 'ad-go/cluster is not installed.']);
        }

        return $this->dispatchTest('combined', $node, [
            'node'     => $this->nodeTestParams($node),
            'database' => $this->databaseTestParams($node),
        ]);
    }

    /**
     * @return array{protocol: string, host: string, port: string, user: string, pass: string}
     */
    private function nodeTestParams(string $node): array
    {
        $settings = service('settings');
        $protocol = (string) ($settings->get('Nodes.protocol', $node) ?? 'FTP');
        $family   = in_array($protocol, ['SSH', 'SCP'], true) ? 'ssh' : 'ftp';

        return [
            'protocol' => $protocol,
            'host'     => (string) ($settings->get('Nodes.' . $family . 'Host', $node) ?? ''),
            'port'     => (string) ($settings->get('Nodes.' . $family . 'Port', $node) ?? ''),
            'user'     => (string) ($settings->get('Nodes.' . $family . 'User', $node) ?? ''),
            'pass'     => (string) ($settings->get('Nodes.' . $family . 'Pass', $node) ?? ''),
        ];
    }

    /**
     * @return array{driverType: string, host: string, port: string, user: string, pass: string, database: string}
     */
    private function databaseTestParams(string $node): array
    {
        $settings = service('settings');
        $type     = (string) ($settings->get('Databases.type', $node) ?? 'mysql');

        return [
            'driverType' => $type,
            'host'       => (string) ($settings->get('Databases.' . $type . 'Host', $node) ?? ''),
            'port'       => (string) ($settings->get('Databases.' . $type . 'Port', $node) ?? ''),
            'user'       => (string) ($settings->get('Databases.' . $type . 'User', $node) ?? ''),
            'pass'       => (string) ($settings->get('Databases.' . $type . 'Pass', $node) ?? ''),
            'database'   => (string) ($settings->get('Databases.' . $type . 'Database', $node) ?? ''),
        ];
    }

    /**
     * Shared by testNode() above - decides HOW to actually run a
     * connection test for $node and returns the CI4 JSON response either
     * way. The credentials being tested (a Databases row's host is often
     * literally 127.0.0.1) only mean something from the TARGET node's own
     * local network position - running the check wherever the admin's
     * browser session happens to be connected (this app's previous
     * behavior) silently tested the WRONG thing for any node other than
     * itself. Found live 2026-08-19: testing bak's Nodes-table SSH row
     * from h1q's dashboard timed out (h1q genuinely can't reach
     * 192.168.0.253) while the identical test run from res's own local
     * network context succeeded instantly - and bak/res have no public
     * URL at all, so there was previously no way to test their own local
     * resources through this UI whatsoever.
     *
     * Three cases:
     * - $node IS this node: run the check right here, no network hop -
     *   the view no longer offers a badge for this case at all (see
     *   nodeRows()/the view - a node testing a connection to itself is
     *   meaningless), but this branch stays as the correct, safe fallback
     *   for any other caller, and avoids any self-referential-HTTPS
     *   weirdness a loopback call to this node's own public URL could hit.
     * - $node is 'public': reachable directly - a synchronous signed
     *   POST to that node's own cluster/test-connection (see
     *   RemoteTestController), which runs the check there and returns
     *   the result in the same response.
     * - $node is 'nat' (or an unrecognized type - see below): NOT
     *   reachable directly, ever (see README "How it works"). Enqueued
     *   locally (RemoteTestQueue) for that node's own
     *   cluster:pull cycle to claim, execute, and report back next time
     *   it asks THIS node "anything pending for me" - see PullSync::
     *   pullTestRequests(). The response here is `{pending: true,
     *   requestId, nodeType}`, not a result; settings.js polls
     *   testResult() below until one shows up or its own client-side
     *   timeout gives up, and picks its "why waiting" message off
     *   nodeType rather than assuming NAT. Latency is bounded by that
     *   pull cadence (as fast as ~5s with Config\Cluster::
     *   $pullLoopSeconds tuned down, up to ~60s on the plain
     *   once-a-minute cron default) - not instant, by design.
     */
    private function dispatchTest(string $kind, string $node, array $params): ResponseInterface
    {
        $cluster = new \AdGo\Cluster\Cluster();
        $params['kind'] = $kind;

        if ($node === $cluster->thisNodeName()) {
            $result = $this->localizeTestResult($this->runLocalTest($kind, $params));

            return $this->response->setJSON($result + ['csrf' => $this->csrfPayload()]);
        }

        $target = $cluster->node($node);
        if (($target['type'] ?? '') === 'public') {
            $result = $this->localizeTestResult($this->callRemoteTest($cluster, (string) $target['baseURL'], $params));

            return $this->response->setJSON($result + ['csrf' => $this->csrfPayload()]);
        }

        // 'nat' (or an unrecognized type - fail toward the safe/async path
        // rather than assuming direct reachability).
        $requestId = bin2hex(random_bytes(16));
        if (class_exists(\AdGo\Cluster\RemoteTestQueue::class)) {
            (new \AdGo\Cluster\RemoteTestQueue())->enqueue($node, $requestId, $params);
        }

        return $this->response->setJSON([
            'pending'   => true,
            'requestId' => $requestId,
            'node'      => $node,
            // 'nat' most of the time, but this IS also the path an
            // unrecognized type falls back to (see this method's own
            // comment above) - settings.js picks its "why waiting"
            // message off this rather than assuming NAT, so that edge
            // case doesn't claim a "no direct connection" reason that
            // may not actually be true.
            'nodeType'  => $target['type'] ?? null,
            'csrf'      => $this->csrfPayload(),
        ]);
    }

    // Same single dispatch point RemoteTestController/PullSync route
    // every remote check through too - see CapabilityChecker's own
    // docblock. Was its own $kind==='database' ternary here until
    // 2026-08-19; that made THREE independent copies of the same
    // kind->checker mapping across this repo and ad-go/cluster.
    private function runLocalTest(string $kind, array $params): array
    {
        return (new \AdGo\Cluster\CapabilityChecker())->run($kind, $params);
    }

    private function callRemoteTest(\AdGo\Cluster\Cluster $cluster, string $baseURL, array $params): array
    {
        try {
            $client   = $cluster->peerClient($baseURL, 15);
            $response = $client->post('cluster/test-connection', [
                'headers' => ['Authorization' => $cluster->authHeader()],
                'json'    => $params,
            ]);
            $decoded = json_decode($response->getBody(), true);

            return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'Invalid response from target node.', 'ms' => 0.0];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'ms' => 0.0];
        }
    }

    // Polling endpoint for the async NAT-relay path (see dispatchTest()) -
    // shared by both the Nodes and Databases tables since a requestId is
    // already a globally unique opaque token, no need for two near-
    // identical endpoints. {pending:true} until RemoteTestQueue actually
    // has a result recorded (see RemoteTestController::testResult(), the
    // NAT node's own report-back call) - settings.js keeps polling on
    // that response and stops once a real ok/error result (or its own
    // client-side timeout) arrives.
    public function testResult(): ResponseInterface
    {
        if (! (auth()->user()?->inGroup('superadmin'))) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        $requestId = (string) $this->request->getGet('requestId');
        if ($requestId === '' || ! class_exists(\AdGo\Cluster\RemoteTestQueue::class)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'Invalid request.']);
        }

        $result = (new \AdGo\Cluster\RemoteTestQueue())->result($requestId);
        if ($result === null) {
            return $this->response->setJSON(['pending' => true]);
        }

        return $this->response->setJSON($this->localizeTestResult($result) + ['csrf' => $this->csrfPayload()]);
    }

    // Translates the 'errorCode' (+ optional 'errorArgs') NodeConnectionChecker/
    // DbConnectionChecker/CapabilityChecker attach onto a KNOWN, fixed-message
    // failure into this admin's own active locale, overwriting the plain-English
    // 'error' string those classes return - see NodeConnectionChecker::
    // checkParams()'s own docblock for why that translation can't happen there
    // (this package ships no Language files, and a remote/NAT check's result
    // crosses the wire from a DIFFERENT node's PHP process that has no idea
    // what locale the requesting admin even has active). A caught Throwable's
    // own ->getMessage() (no 'errorCode' set) is left exactly as returned -
    // driver/library text, not practical to translate. Handles both a flat
    // result and 'combined' testNode()'s own nested 'node'/'database' shape.
    private function localizeTestResult(array $result): array
    {
        foreach (['node', 'database'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                $result[$key] = $this->localizeTestResult($result[$key]);
            }
        }
        if (isset($result['errorCode']) && is_string($result['errorCode'])) {
            $args            = is_array($result['errorArgs'] ?? null) ? $result['errorArgs'] : [];
            $result['error'] = lang('App.connErr' . ucfirst($result['errorCode']), $args);
        }

        return $result;
    }

    // Export button on the Cluster card - dumps nodeRows()/databaseRows()
    // merged BY NODE (each node's Databases record nested under its own
    // "database" key) into a downloadable {host}-{date time}.json, same
    // shape this project's own external deploy tooling already produces
    // (asyncron.nodes.json - see the sibling asyncron.nodes.json file the
    // node-restructuring pass in this project's history generated by
    // hand) rather than a bespoke one only this endpoint understood.
    // Includes plaintext credentials, same as the table itself already
    // shows a superadmin on screen - not a new exposure, just a portable
    // copy of it.
    public function exportSettings(): ResponseInterface
    {
        if (! (auth()->user()?->inGroup('superadmin'))) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        $cluster = class_exists(\AdGo\Cluster\Cluster::class) ? new \AdGo\Cluster\Cluster() : null;
        $host    = $cluster?->thisNodeName() ?: (gethostname() ?: 'node');

        $databaseRows = $this->databaseRows();
        $nodes        = [];
        foreach ($this->nodeRows() as $name => $props) {
            $nodes[$name]             = $props;
            $nodes[$name]['database'] = $databaseRows[$name] ?? [];
        }

        $payload = [
            'exportedAt'        => date('Y-m-d H:i:s'),
            'host'              => $host,
            // The one shared secret every node needs to authenticate to
            // every other (Config\Cluster::$secretToken, NOT a per-node
            // NODE_PROPS field) - included so an export/import round trip
            // can carry it along with the topology instead of leaving it
            // to be typed in by hand on every other node. '' when this
            // node hasn't got one configured itself yet.
            'secretToken'       => $cluster?->secretToken() ?? '',
            // UNLIKE secretToken, this is NOT shared - it's THIS node's own
            // RSA private key (see Config\Cluster::$signingPrivateKey's own
            // docblock on why per-node keys exist at all), paired with
            // 'host' above (also this node's own identity) so
            // importSettings()/importCluster() can restore it later ONLY
            // when re-importing this exact file back onto the SAME node it
            // came from (matched by host === the freshly auto-detected
            // thisNode - see configureClusterIdentity()) - never onto a
            // DIFFERENT node, which would silently make it sign as an
            // identity it isn't. '' when this node hasn't generated a
            // keypair yet (still on the legacy shared-secret fallback).
            'signingPrivateKey' => $cluster?->signingPrivateKey() ?? '',
            'nodes'             => $nodes,
        ];

        $filename = $host . '-' . date('Y-m-d_H-i-s') . '.json';

        return $this->response
            ->setContentType('application/json')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    // Import button's counterpart - loads a file in exportSettings()'s exact
    // shape (also importCluster()'s own - see that method's docblock) and
    // overwrites the Settings table from it. Unlike importCluster(), this
    // never touches cluster.nodes itself - every node in the file must
    // already be $known (in the live registry); this only updates
    // credentials for nodes the cluster already has. It DOES still write
    // cluster.thisNode/secretToken when the file carries them (see
    // configureClusterIdentity()) - on an already-populated cluster (every
    // real node, after importCluster() has run once) this Import button is
    // the ONLY import path left, so it has to be the one that finishes
    // wiring up sync, not just credentials. Validated in a FIRST pass,
    // entirely, before a SECOND pass writes anything - a malformed or
    // partly-invalid file must not leave the Settings table half-overwritten
    // (same all-or-nothing principle used for cluster.dbSyncGroup elsewhere
    // in this project).
    public function importSettings(): ResponseInterface
    {
        if (! (auth()->user()?->inGroup('superadmin'))) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        $file = $this->request->getFile('file');
        if ($file === null || ! $file->isValid()) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'No valid file uploaded.']);
        }

        $decoded = json_decode((string) file_get_contents($file->getTempName()), true);
        if (! is_array($decoded) || ! isset($decoded['nodes']) || ! is_array($decoded['nodes'])) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'Invalid file - expected {"nodes": {"<name>": {...,"database":{...}}}}.']);
        }

        $known = class_exists(\AdGo\Cluster\Cluster::class) ? (new \AdGo\Cluster\Cluster())->allNodes() : [];

        // Splits each node's nested "database" object out into its own
        // flat map alongside the node's own connection props - the rest of
        // this method validates/writes both exactly like the old separate
        // top-level "nodes"/"databases" keys did. 'selected' (see
        // nodeRows()/databaseRows()) and 'originalName' (asyncron.nodes.
        // json's own traceability field - see that file's own docblock)
        // are both export/reference-only, never real props, so both are
        // dropped here rather than added to NODE_PROPS, which would let
        // either be set independently of the field it's just mirroring.
        $nodesFlat     = [];
        $databasesFlat = [];
        foreach ($decoded['nodes'] as $node => $props) {
            if (! is_array($props)) {
                return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'nodes.' . $node . ': expected an object of properties.']);
            }
            $database = is_array($props['database'] ?? null) ? $props['database'] : [];
            unset($props['selected'], $props['originalName'], $props['database'], $database['selected']);
            $nodesFlat[$node]     = $props;
            $databasesFlat[$node] = $database;
        }

        foreach ($nodesFlat as $node => $props) {
            if ($error = $this->invalidImportRow((string) $node, $props, $known, self::NODE_PROPS)) {
                return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'nodes.' . $node . ': ' . $error]);
            }
            foreach ($props as $prop => $value) {
                if ($prop === 'type' && ! in_array((string) $value, self::NODE_TYPES, true)) {
                    return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => "nodes.{$node}: invalid type '{$value}'."]);
                }
                if ($prop === 'protocol' && ! in_array((string) $value, self::NODE_PROTOCOLS, true)) {
                    return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => "nodes.{$node}: invalid protocol '{$value}'."]);
                }
            }
        }
        foreach ($databasesFlat as $node => $props) {
            if ($error = $this->invalidImportRow((string) $node, $props, $known, self::DATABASE_PROPS)) {
                return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'nodes.' . $node . '.database: ' . $error]);
            }
            foreach ($props as $prop => $value) {
                if ($prop === 'type' && ! in_array((string) $value, self::DATABASE_TYPES, true)) {
                    return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => "nodes.{$node}.database: invalid type '{$value}'."]);
                }
            }
        }

        $settings = service('settings');
        foreach ($nodesFlat as $node => $props) {
            foreach ($props as $prop => $value) {
                $settings->set('Nodes.' . $prop, (string) $value, (string) $node);
            }
        }
        foreach ($databasesFlat as $node => $props) {
            foreach ($props as $prop => $value) {
                $settings->set('Databases.' . $prop, (string) $value, (string) $node);
            }
        }

        $warning = $this->configureClusterIdentity($decoded, array_map(
            static fn (array $p): string => (string) ($p['url'] ?? ''),
            $nodesFlat
        ));

        return $this->response->setJSON(['ok' => true, 'csrf' => $this->csrfPayload(), 'warning' => $warning]);
    }

    // "Import cluster" - shown on the Settings page ONLY when the node
    // registry is entirely empty (Config\Cluster::$nodes is env-only,
    // parsed once at bootstrap by nodesFromEnv() - there was no runtime
    // write path into it before this). Bootstraps the WHOLE mesh in one
    // upload from a file shaped like this project's own external deploy
    // tooling produces (asyncron.nodes.json: {"nodes": {"<name>": {"url",
    // "type", "protocol", "ftpHost"..., "sshHost"..., "database": {...}}}})
    // instead of hand-editing .env node by node. Deliberately refuses once
    // ANY node is already configured - this is a first-run bootstrap, not
    // a merge/overwrite tool; changing an already-running mesh's topology
    // belongs to .env plus a real redeploy (see this project's own
    // install.ps1), not a web upload with no rollback.
    public function importCluster(): ResponseInterface
    {
        if (! (auth()->user()?->inGroup('superadmin'))) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        $existing = class_exists(\AdGo\Cluster\Cluster::class) ? (new \AdGo\Cluster\Cluster())->allNodes() : [];
        if ($existing !== []) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'A cluster is already configured - edit .env directly to change it.']);
        }

        $file = $this->request->getFile('file');
        if ($file === null || ! $file->isValid()) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'No valid file uploaded.']);
        }

        $decoded = json_decode((string) file_get_contents($file->getTempName()), true);
        if (! is_array($decoded) || ! isset($decoded['nodes']) || ! is_array($decoded['nodes']) || $decoded['nodes'] === []) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'Invalid file - expected {"nodes": {"<name>": {"url": ..., "type": ...}}}.']);
        }

        // Validated in a FIRST pass, entirely, before anything is written -
        // same all-or-nothing principle importSettings() already uses.
        $entries = [];
        foreach ($decoded['nodes'] as $name => $props) {
            $name = (string) $name;
            if ($name === '' || ! is_array($props)) {
                return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => "nodes.{$name}: expected an object of properties."]);
            }
            $url = (string) ($props['url'] ?? '');
            if ($url === '') {
                return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => "nodes.{$name}: missing url."]);
            }
            $type = (string) ($props['type'] ?? 'public');
            if (! in_array($type, self::NODE_TYPES, true)) {
                return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => "nodes.{$name}: invalid type '{$type}'."]);
            }
            if (isset($props['protocol']) && ! in_array((string) $props['protocol'], self::NODE_PROTOCOLS, true)) {
                return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => "nodes.{$name}: invalid protocol '{$props['protocol']}'."]);
            }
            $database = is_array($props['database'] ?? null) ? $props['database'] : [];
            if (isset($database['type']) && ! in_array((string) $database['type'], self::DATABASE_TYPES, true)) {
                return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => "nodes.{$name}.database: invalid type '{$database['type']}'."]);
            }
            $entries[$name] = ['baseURL' => rtrim($url, '/'), 'type' => $type, 'props' => $props, 'database' => $database];
        }

        if (! $this->writeClusterNodesEnv($entries)) {
            return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'error' => 'Could not write .env.']);
        }

        // Seeds the Nodes/Databases credential tables from the SAME file,
        // straight after .env - same fields importSettings() already
        // writes (NODE_PROPS/DATABASE_PROPS), just sourced from this
        // upload's nested "database" object per node instead of a second
        // top-level "databases" key. 'type' is skipped here (unlike
        // importSettings()) - it's already correct via the registry
        // default nodeRows() falls back to, so writing it again would just
        // duplicate what .env now already says.
        $settings = service('settings');
        foreach ($entries as $name => $entry) {
            foreach (self::NODE_PROPS as $prop) {
                if ($prop === 'type') {
                    continue;
                }
                if (array_key_exists($prop, $entry['props']) && is_scalar($entry['props'][$prop])) {
                    $settings->set('Nodes.' . $prop, (string) $entry['props'][$prop], $name);
                }
            }
            foreach (self::DATABASE_PROPS as $prop) {
                if (array_key_exists($prop, $entry['database']) && is_scalar($entry['database'][$prop])) {
                    $settings->set('Databases.' . $prop, (string) $entry['database'][$prop], $name);
                }
            }
        }

        $warning = $this->configureClusterIdentity($decoded, array_map(
            static fn (array $e): string => $e['baseURL'],
            $entries
        ));

        return $this->response->setJSON(['ok' => true, 'csrf' => $this->csrfPayload(), 'warning' => $warning]);
    }

    // Shared by importSettings()/importCluster() - "an import should
    // configure thisNode and secretToken" per an explicit request, prompted
    // by this project's own live status check finding cluster.nodes already
    // imported on every real node but cluster.thisNode/secretToken both
    // still blank everywhere: ClusterAuthFilter rejects every peer-to-peer
    // call outright while secretToken is empty (verifyAuthHeader() requires
    // $expected !== ''), and an empty thisNode means publicPeers()/
    // allPeers() can't correctly exclude "this node itself" from its own
    // peer list either - the node registry alone was never enough to make
    // sync actually work.
    //
    // thisNode is auto-detected by matching THIS node's own already-correct
    // app.baseURL (set per-node at install time - see asyncron.php's
    // doGetInstall()) against each imported node's url, comparing only the
    // HOST (scheme/trailing-slash differences are common and meaningless
    // here). Never guessed from the file's own top-level "host" field -
    // that's the EXPORTING node's name, which means nothing on a different
    // importing node reading the same file.
    //
    // @param array<string, string> $nodeUrls node name => url, already
    //                                        flattened by the caller (the
    //                                        two callers' own intermediate
    //                                        shapes differ, a plain map is
    //                                        the one thing both can produce
    //                                        cheaply)
    // @return string|null a warning to surface to the caller if thisNode
    //                      couldn't be determined (secretToken is written
    //                      regardless - the two are independent)
    private function configureClusterIdentity(array $decoded, array $nodeUrls): ?string
    {
        $envLines = [];

        // Newlines/quotes stripped rather than escaped - a real secret
        // token has no legitimate reason to contain either, and stripping
        // is enough to stop either from breaking the .env line itself.
        $secretToken = isset($decoded['secretToken']) ? trim((string) $decoded['secretToken']) : '';
        $secretToken = str_replace(['"', "\r", "\n"], '', $secretToken);
        // Generated, not left blank, when the import carried none - an
        // empty secretToken means ClusterAuthFilter::verifyAuthHeader()
        // rejects EVERY peer-to-peer call outright (see that method's own
        // docblock: "$expected !== ''"), so an import that skips this
        // silently breaks the whole mesh rather than just the legacy
        // fallback path.
        if ($secretToken === '') {
            $secretToken = bin2hex(random_bytes(32));
        }
        $envLines['cluster.secretToken'] = '"' . $secretToken . '"';

        $warning  = null;
        $ownHost  = parse_url((string) config('App')->baseURL, PHP_URL_HOST);
        $thisNode = null;
        if (is_string($ownHost) && $ownHost !== '') {
            foreach ($nodeUrls as $name => $url) {
                $nodeHost = parse_url($url, PHP_URL_HOST);
                if (is_string($nodeHost) && $nodeHost !== '' && strcasecmp($nodeHost, $ownHost) === 0) {
                    $thisNode = (string) $name;
                    break;
                }
            }
        }
        if ($thisNode !== null) {
            $envLines['cluster.thisNode'] = $thisNode;
        } else {
            $warning = "Could not auto-detect cluster.thisNode - no imported node's URL host matches this node's own app.baseURL ({$ownHost}). Set it by hand in .env if this node should participate in the mesh.";
        }

        // Only restored when re-importing this EXACT file back onto the
        // SAME node exportSettings() built it on (its own 'host' field
        // matches the thisNode this import just auto-detected) - see
        // exportSettings()'s own docblock on why this is never applied
        // across nodes, unlike secretToken above. base64 has no legitimate
        // reason to contain a quote/newline either, same stripping as
        // secretToken.
        $exportedHost      = isset($decoded['host']) ? trim((string) $decoded['host']) : '';
        $signingPrivateKey = isset($decoded['signingPrivateKey']) ? trim((string) $decoded['signingPrivateKey']) : '';
        $signingPrivateKey = str_replace(['"', "\r", "\n"], '', $signingPrivateKey);
        $restoredOwnKey    = $signingPrivateKey !== '' && $thisNode !== null && $thisNode === $exportedHost;
        if ($restoredOwnKey) {
            $envLines['cluster.signingPrivateKey'] = '"' . $signingPrivateKey . '"';
        }

        // Generated, not left blank, when this import carries no usable
        // signing key for THIS node (either the export had none, or it was
        // someone else's - see restoredOwnKey above, only a re-import onto
        // the SAME node that originally exported it counts). Falling back
        // to the legacy shared-secret scheme silently is exactly what
        // Config\Cluster::$signingPrivateKey's own docblock calls a
        // regression once real keys are meant to be in use everywhere.
        $newPublicKeyPem = null;
        if (! $restoredOwnKey) {
            $keypair                               = $this->generateSigningKeypair();
            $envLines['cluster.signingPrivateKey'] = '"' . base64_encode($keypair['privatePem']) . '"';
            $newPublicKeyPem                       = $keypair['publicPem'];
        }

        if ($envLines !== [] && ! $this->writeEnvLines($envLines)) {
            return 'Could not write .env for cluster.thisNode/secretToken/signingPrivateKey.';
        }

        // A freshly-generated key is useless until every OTHER node's
        // registry carries its public half too - update THIS node's own
        // entry and let Cluster::broadcastNodeUpsert() relay it out (see
        // that method's own docblock), same propagation path addNode()
        // now uses. Only possible once thisNode is known - an import that
        // couldn't auto-detect it already surfaces $warning above, and the
        // key still works locally (it's saved to .env either way), it just
        // can't be verified by peers until an admin sets cluster.thisNode
        // and re-saves by hand.
        if ($newPublicKeyPem !== null && $thisNode !== null && class_exists(\AdGo\Cluster\Cluster::class)) {
            $cluster = new \AdGo\Cluster\Cluster();
            $entries = $cluster->allNodes();
            if (array_key_exists($thisNode, $entries)) {
                $entries[$thisNode]['publicKey'] = base64_encode($newPublicKeyPem);
                if ($this->writeClusterNodesEnv($entries)) {
                    $cluster->broadcastNodeUpsert($thisNode, $entries[$thisNode]);
                }
            }
        }

        return $warning;
    }

    // RSA-2048/SHA256 keypair for a node's OWN cluster.signingPrivateKey -
    // see that config property's own docblock for the scheme this feeds.
    // Called only when an import carries no usable key for THIS node
    // (configureClusterIdentity() above) - every node ends up with a real
    // keypair instead of silently staying on the legacy shared-secret
    // fallback forever.
    //
    // @return array{privatePem: string, publicPem: string}
    private function generateSigningKeypair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            throw new \RuntimeException('Could not generate an RSA keypair (openssl_pkey_new failed).');
        }

        openssl_pkey_export($resource, $privatePem);
        $details = openssl_pkey_get_details($resource);

        return ['privatePem' => (string) $privatePem, 'publicPem' => (string) $details['key']];
    }

    // Per an explicit 2026-08-22 request: adding node1+node2 by hand (see
    // addNode()) leaves cluster.thisNode/secretToken/signingPrivateKey all
    // blank - nothing sets those except an Import, and until they're set
    // EVERY peer-to-peer call (including the connection-test badge click
    // that surfaced this) is rejected outright (see ClusterAuthFilter/
    // verifyAuthHeader()'s own docblock). This is the fix: once 2+ nodes
    // exist and this node can identify itself among them, it generates its
    // own identity and exchanges public keys with ONE peer whose FTP/SSH
    // credentials just tested successfully - see clusterHandshake() below
    // for the receiving side and why the handshake itself doesn't need
    // cluster-auth to already exist.
    //
    // Checked from index() (every page load) and addNode() (right after a
    // 2nd node is added) - either can be "the moment" the condition first
    // holds, per this project's own explicit choice not to require a
    // separate manual step. Entirely best-effort: any failure (no peer
    // reachable yet, key generation error, .env not writable) just leaves
    // the cluster not-yet-functional for the next check to retry, never a
    // thrown error surfaced to the page.
    //
    // Deliberately stops after the FIRST peer that both tests successfully
    // AND completes the handshake - two real nodes is already enough to
    // become functional, and every OTHER known peer's own keys get relayed
    // to/from this node the normal way afterward (Cluster::
    // broadcastNodeUpsert(), already called wherever a node's publicKey
    // changes - see addNode()/configureClusterIdentity()'s own callers)
    // now that a real signed channel exists to carry it.
    private function autoStartCluster(): void
    {
        if (! class_exists(\AdGo\Cluster\Cluster::class)) {
            return;
        }

        $cluster = new \AdGo\Cluster\Cluster();
        $nodes   = $cluster->allNodes();
        if (count($nodes) < 2) {
            return;
        }

        $ownName = $this->matchOwnNodeName($nodes);
        if ($ownName === null) {
            return;
        }

        // "Already done" here means a PEER's publicKey is actually on
        // file, not just that this node generated its own identity -
        // own-identity-but-no-peer-key is exactly the state a handshake
        // attempt can leave behind when the peer was briefly unreachable
        // (see the loop below), and that state must keep retrying on the
        // next call, not look "finished" forever because thisNode/
        // secretToken happen to be set.
        foreach ($nodes as $name => $node) {
            if ($name !== $ownName && ($node['publicKey'] ?? '') !== '') {
                return;
            }
        }

        foreach ($nodes as $peerName => $peer) {
            if ($peerName === $ownName || ($peer['type'] ?? '') !== 'public' || ($peer['publicKey'] ?? '') !== '') {
                continue;
            }
            if (! $this->localConnectionTestOk($peerName)) {
                continue;
            }

            // Reuse this node's own identity if an EARLIER attempt already
            // generated one (the response never made it back last time,
            // say) - only generate fresh keys the first time through,
            // never on a retry, so a peer that DID receive the first
            // attempt's key isn't left holding a now-stale one.
            if ($cluster->thisNodeName() !== '' && $cluster->secretToken() !== '') {
                $secretToken     = $cluster->secretToken();
                $ownPublicKeyB64 = (string) ($nodes[$ownName]['publicKey'] ?? '');
            } else {
                try {
                    $keypair = $this->generateSigningKeypair();
                } catch (\Throwable $e) {
                    return;
                }
                $secretToken     = bin2hex(random_bytes(32));
                $ownPublicKeyB64 = base64_encode($keypair['publicPem']);

                if (! $this->writeEnvLines([
                    'cluster.thisNode'          => $ownName,
                    'cluster.secretToken'       => '"' . $secretToken . '"',
                    'cluster.signingPrivateKey' => '"' . base64_encode($keypair['privatePem']) . '"',
                ])) {
                    return;
                }
                // Same "keep the shared config singleton in sync within
                // this SAME request" reasoning writeClusterNodesEnv()
                // already documents for ->nodes - writeEnvLines() only
                // touches the file, and nothing else re-reads .env
                // mid-request. config('Cluster') returns the identical
                // shared instance Cluster::__construct() itself reads, so
                // this is visible to any Cluster object created for the
                // rest of this request, including the one sendHandshake()
                // below builds internally.
                $clusterConfig                    = config('Cluster');
                $clusterConfig->thisNode          = $ownName;
                $clusterConfig->secretToken       = $secretToken;
                $clusterConfig->signingPrivateKey = base64_encode($keypair['privatePem']);

                $entries                        = $nodes;
                $entries[$ownName]['publicKey'] = $ownPublicKeyB64;
                if (! $this->writeClusterNodesEnv($entries)) {
                    return;
                }
                $nodes = $entries;
            }

            $peerPublicKeyB64 = $this->sendHandshake((string) $peer['baseURL'], [
                'name'        => $ownName,
                'baseURL'     => rtrim((string) config('App')->baseURL, '/'),
                'publicKey'   => $ownPublicKeyB64,
                'secretToken' => $secretToken,
            ]);

            if ($peerPublicKeyB64 !== null && $peerPublicKeyB64 !== '') {
                $entries                         = (new \AdGo\Cluster\Cluster())->allNodes();
                $entries[$peerName]['publicKey'] = $peerPublicKeyB64;
                $this->writeClusterNodesEnv($entries);
            }

            // One attempt per invocation, whether or not it fully
            // completed - a failed handshake POST just leaves this node
            // with its own identity generated (see the reuse branch
            // above) for the next call to pick up and retry.
            return;
        }
    }

    // Matches THIS node's own app.baseURL (host only - scheme/trailing-
    // slash differences are common and meaningless here) against a
    // registry's entries to find which one is "us" - same matching
    // configureClusterIdentity() already does for an imported file, reused
    // here since a manually-added registry needs the identical lookup.
    //
    // @param array<string, array{baseURL: string, type: string, publicKey: string}> $nodes
    private function matchOwnNodeName(array $nodes): ?string
    {
        $ownHost = parse_url((string) config('App')->baseURL, PHP_URL_HOST);
        if (! is_string($ownHost) || $ownHost === '') {
            return null;
        }
        foreach ($nodes as $name => $node) {
            $host = parse_url((string) ($node['baseURL'] ?? ''), PHP_URL_HOST);
            if (is_string($host) && $host !== '' && strcasecmp($host, $ownHost) === 0) {
                return (string) $name;
            }
        }

        return null;
    }

    // The "conexiuni testate" (tested connections) gate autoStartCluster()
    // requires before trusting a peer enough to hand it a secretToken -
    // runs the SAME file-sync capability check testNode()'s own local
    // branch uses (CapabilityChecker::run('node', ...)), but unconditionally
    // LOCAL rather than delegated to the target (dispatchTest()'s normal
    // "test from the right vantage point" relay is exactly what's broken
    // pre-bootstrap - see this class's own docblock on why). Database
    // credentials are deliberately NOT part of this gate - a host like
    // '127.0.0.1' only means something from the TARGET's own network
    // position (dispatchTest()'s own docblock again), so testing it from
    // here would be meaningless at best, a false negative at worst; the
    // Database row gets tested for real the normal way once the signed
    // channel this unblocks actually exists.
    private function localConnectionTestOk(string $node): bool
    {
        if (! class_exists(\AdGo\Cluster\CapabilityChecker::class)) {
            return false;
        }

        $result = (new \AdGo\Cluster\CapabilityChecker())->run('node', $this->nodeTestParams($node));

        return (bool) ($result['ok'] ?? false);
    }

    // Fire-and-forget POST to a peer's own clusterHandshake() (see that
    // method's own docblock for what it does with this) - null on ANY
    // failure (unreachable, non-200, unparsable body), never an exception,
    // since autoStartCluster() treats "couldn't complete this peer's
    // handshake" as "try again next time", not a hard error.
    private function sendHandshake(string $baseURL, array $payload): ?string
    {
        if (! class_exists(\AdGo\Cluster\Cluster::class)) {
            return null;
        }

        try {
            $client   = (new \AdGo\Cluster\Cluster())->peerClient($baseURL, 15);
            $response = $client->post('cluster/bootstrap-handshake', ['form_params' => $payload]);
            $decoded  = json_decode((string) $response->getBody(), true);

            return (is_array($decoded) && ($decoded['ok'] ?? false)) ? (string) ($decoded['publicKey'] ?? '') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // Receiving side of autoStartCluster()'s handshake - registered
    // WITHOUT the 'session' filter in app/Config/Routes.php (a peer server
    // calls this, not a logged-in browser) and deliberately NOT behind the
    // ad-go/cluster package's own 'cluster-auth' filter either: that
    // filter verifies a signed header against a publicKey this node
    // doesn't have on file yet (this IS the call that first provides it) -
    // exactly the chicken-and-egg every other cluster/* route never has to
    // solve, since they all run AFTER a cluster is already configured.
    //
    // Trust instead comes from registry pre-agreement, not the request
    // itself: this only proceeds if the caller's claimed name is ALREADY a
    // node THIS node's own admin manually registered (same URL host - see
    // matchOwnNodeName()'s own comment on why host-only), and refuses
    // outright once that SPECIFIC name already has a publicKey on file
    // (see the per-peer guard below), closing the door on a re-keying
    // attempt through this same unauthenticated path without blocking a
    // later, different peer from still pairing normally.
    //
    // This is real TOFU (trust-on-first-use) trust, not cryptographic
    // proof the caller actually controls the claimed baseURL - accepted
    // deliberately here per an explicit 2026-08-22 "start automatically"
    // request, appropriate for a small set of self-administered nodes
    // whose names an admin already chose, not a hardened defense against a
    // targeted attacker who both reaches this public endpoint AND guesses
    // a registered peer name before the real pairing happens.
    public function clusterHandshake(): ResponseInterface
    {
        if (! class_exists(\AdGo\Cluster\Cluster::class)) {
            return $this->response->setStatusCode(503)->setJSON(['ok' => false]);
        }

        $cluster = new \AdGo\Cluster\Cluster();
        $known   = $cluster->allNodes();

        $name         = trim((string) $this->request->getPost('name'));
        $baseURL      = trim((string) $this->request->getPost('baseURL'));
        $publicKeyB64 = trim((string) $this->request->getPost('publicKey'));
        $secretToken  = trim((string) $this->request->getPost('secretToken'));

        if ($name === '' || preg_match('/^[a-zA-Z0-9_-]+$/', $name) !== 1 || $baseURL === '' || $publicKeyB64 === '') {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'Invalid handshake payload.']);
        }

        if (! array_key_exists($name, $known)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => "Unknown peer '{$name}'."]);
        }
        // Per-peer, not "has this node paired with ANYONE yet" - a THIRD
        // node bootstrapping later must still be able to pair even after
        // this node already completed a handshake with a first peer. What
        // this closes is re-keying: once $name's publicKey is on file,
        // this same unauthenticated path can never overwrite it - any
        // future change to an ALREADY-trusted peer's key has to go through
        // a signed, authenticated call instead (out of scope here).
        if (($known[$name]['publicKey'] ?? '') !== '') {
            return $this->response->setStatusCode(409)->setJSON(['ok' => false, 'error' => "'{$name}' is already paired."]);
        }
        $knownHost   = parse_url((string) $known[$name]['baseURL'], PHP_URL_HOST);
        $claimedHost = parse_url($baseURL, PHP_URL_HOST);
        if (! is_string($knownHost) || $knownHost === '' || ! is_string($claimedHost) || $claimedHost === '' || strcasecmp($knownHost, $claimedHost) !== 0) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => "URL for '{$name}' does not match this node's own registry."]);
        }

        $entries                     = $known;
        $entries[$name]['publicKey'] = $publicKeyB64;
        if (! $this->writeClusterNodesEnv($entries)) {
            return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'error' => 'Could not write .env.']);
        }

        $ownName = $this->matchOwnNodeName($entries);
        if ($ownName === null) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => "Could not identify this node's own registry entry."]);
        }

        // Reuse this node's own identity if it already has one (its own
        // earlier handshake attempt against a DIFFERENT, or the same,
        // peer got this far before) - same "never regenerate on a retry"
        // reasoning autoStartCluster() documents, so a peer that already
        // received an earlier version of this key isn't left with a
        // now-stale one.
        if ($cluster->thisNodeName() !== '' && $cluster->secretToken() !== '') {
            $ownPublicKeyB64 = (string) ($entries[$ownName]['publicKey'] ?? '');
        } else {
            try {
                $keypair = $this->generateSigningKeypair();
            } catch (\Throwable $e) {
                return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'error' => 'Could not generate a signing keypair.']);
            }
            $ownSecretToken  = $secretToken !== '' ? $secretToken : bin2hex(random_bytes(32));
            $ownPublicKeyB64 = base64_encode($keypair['publicPem']);

            if (! $this->writeEnvLines([
                'cluster.thisNode'          => $ownName,
                'cluster.secretToken'       => '"' . $ownSecretToken . '"',
                'cluster.signingPrivateKey' => '"' . base64_encode($keypair['privatePem']) . '"',
            ])) {
                return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'error' => 'Could not write .env.']);
            }

            $entries[$ownName]['publicKey'] = $ownPublicKeyB64;
            $this->writeClusterNodesEnv($entries);
        }

        return $this->response->setJSON(['ok' => true, 'name' => $ownName, 'publicKey' => $ownPublicKeyB64]);
    }

    // Builds the single cluster.nodes line (see writeEnvLines() for the
    // actual file write) - format matches Config\Cluster::nodesFromEnv()'s
    // own parser exactly: name|baseURL|type[|publicKey], comma-separated.
    // Preserves each entry's 'publicKey' when present (addNode() below
    // always carries the FULL existing registry through here, not just the
    // one node being added/changed - dropping an already-configured peer's
    // signing key here would silently downgrade it back to the legacy
    // shared-secret scheme, see Config\Cluster::$signingPrivateKey's own
    // docblock on why that's a real regression, not a cosmetic one).
    private function writeClusterNodesEnv(array $entries): bool
    {
        $line = '"' . implode(',', array_map(
            static function (string $name, array $entry): string {
                $publicKey = (string) ($entry['publicKey'] ?? '');

                return $name . '|' . $entry['baseURL'] . '|' . $entry['type'] . ($publicKey !== '' ? '|' . $publicKey : '');
            },
            array_keys($entries),
            $entries
        )) . '"';

        if (! $this->writeEnvLines(['cluster.nodes' => $line])) {
            return false;
        }

        // config('Cluster') is a shared singleton (CI4 Factories cache) -
        // already loaded from .env once, earlier in THIS same request,
        // before this write happened. Without refreshing it here, a
        // caller later in the SAME request (configureClusterIdentity()'s
        // own allNodes() read, right after an import writes brand-new
        // entries) would still see the pre-write registry, not what .env
        // now actually holds - a fresh process picks up the real file
        // fine, but nothing forces one before this request finishes.
        if (class_exists(\AdGo\Cluster\Cluster::class)) {
            config('Cluster')->nodes = array_map(
                static fn (array $entry): array => [
                    'baseURL'   => rtrim((string) $entry['baseURL'], '/') . '/',
                    'type'      => (string) $entry['type'],
                    'publicKey' => (string) ($entry['publicKey'] ?? ''),
                ],
                $entries
            );
        }

        return true;
    }

    // Writes/replaces one or more plain "key = value" lines in .env in a
    // SINGLE read-modify-write pass - same read-modify-write shape as
    // InstallCommand's own ensure*() helpers (see app/Commands/
    // InstallCommand.php), just able to touch several keys at once (one
    // file round trip instead of one per key) and triggered from the web
    // side instead of the installer. $value is used AS-IS - already fully
    // formatted/quoted by the caller if it needs to be; this has no opinion
    // on quoting, only on finding-or-appending the right line per key.
    private function writeEnvLines(array $lines): bool
    {
        $envPath = ROOTPATH . '.env';
        $env     = @file_get_contents($envPath);
        if ($env === false) {
            return false;
        }

        foreach ($lines as $key => $value) {
            $line    = $key . ' = ' . $value;
            $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=.*$/m';
            $env     = preg_match($pattern, $env) === 1
                ? preg_replace($pattern, $line, $env, 1)
                : rtrim($env) . "\n" . $line . "\n";
        }

        return file_put_contents($envPath, $env) !== false;
    }

    // "Add node" - the two-row block at the end of the Cluster table (see
    // app/Views/Settings/index.php), same shape as every real node above
    // it (node connection row + database row) so every field is editable
    // from the start, name included. Unlike importCluster(), this works
    // whether or not the mesh is already configured: it always carries the
    // FULL existing registry (Cluster::allNodes(), publicKey included)
    // through writeClusterNodesEnv() alongside the one new entry, so
    // adding a node never touches any other node's config. Deliberately a
    // single-shot create (every field submitted together, not per-field
    // autosave like the rest of the table) - a brand-new node isn't
    // "known" to updateNode()/updateDatabase() (both check
    // array_key_exists($node, allNodes())) until cluster.nodes itself
    // already lists it, so there's no field to autosave until AFTER this
    // endpoint runs. Once it succeeds, the new node is a completely normal
    // row - the client reloads the page, and every field (Databases
    // included, now seeded from what was typed into the db* fields here
    // rather than always blank) uses the exact same reactive autosave as
    // any node this cluster started with.
    public function addNode(): ResponseInterface
    {
        if (! (auth()->user()?->inGroup('superadmin'))) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        $name = trim((string) $this->request->getPost('name'));
        $url  = trim((string) $this->request->getPost('url'));
        $type = (string) $this->request->getPost('type') ?: 'public';
        $protocol = (string) $this->request->getPost('protocol') ?: 'FTP';
        $host = (string) $this->request->getPost('host');
        $port = (string) $this->request->getPost('port');
        $user = (string) $this->request->getPost('user');
        $pass = (string) $this->request->getPost('pass');
        $dbType     = (string) $this->request->getPost('dbType') ?: 'mysql';
        $dbDatabase = (string) $this->request->getPost('dbDatabase');
        $dbHost     = (string) $this->request->getPost('dbHost');
        $dbPort     = (string) $this->request->getPost('dbPort');
        $dbUser     = (string) $this->request->getPost('dbUser');
        $dbPass     = (string) $this->request->getPost('dbPass');

        if (! in_array($dbType, self::DATABASE_TYPES, true)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => "Invalid database type '{$dbType}'."]);
        }

        // Same charset .env itself already constrains node names to
        // (nodesFromEnv() splits fields on '|' and entries on ',') - kept
        // strict rather than merely escaping those two characters, since
        // this value also becomes a Settings $context and appears
        // unescaped in Cluster::authHeader()'s signed message.
        if ($name === '' || preg_match('/^[a-zA-Z0-9_-]+$/', $name) !== 1) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'Invalid node name - letters, digits, "-" and "_" only.']);
        }
        if ($url === '' || str_contains($url, '|') || str_contains($url, ',')) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'Missing or invalid URL.']);
        }
        if (! in_array($type, self::NODE_TYPES, true)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => "Invalid type '{$type}'."]);
        }
        if (! in_array($protocol, self::NODE_PROTOCOLS, true)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => "Invalid protocol '{$protocol}'."]);
        }

        $existing = class_exists(\AdGo\Cluster\Cluster::class) ? (new \AdGo\Cluster\Cluster())->allNodes() : [];
        if (array_key_exists($name, $existing)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => "A node named '{$name}' already exists."]);
        }

        $entries         = $existing;
        $entries[$name]  = ['baseURL' => rtrim($url, '/'), 'type' => $type, 'publicKey' => ''];
        if (! $this->writeClusterNodesEnv($entries)) {
            return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'error' => 'Could not write .env.']);
        }

        // Propagate to every OTHER node this one already knows about, so
        // the new node doesn't need a manual re-import on each of them -
        // see Cluster::broadcastNodeUpsert()'s own docblock.
        if (class_exists(\AdGo\Cluster\Cluster::class)) {
            (new \AdGo\Cluster\Cluster())->broadcastNodeUpsert($name, $entries[$name]);
        }

        $family   = in_array($protocol, ['SSH', 'SCP'], true) ? 'ssh' : 'ftp';
        $settings = service('settings');
        $settings->set('Nodes.protocol', $protocol, $name);
        if ($host !== '' || $port !== '' || $user !== '' || $pass !== '') {
            $settings->set('Nodes.' . $family . 'Host', $host, $name);
            $settings->set('Nodes.' . $family . 'Port', $port, $name);
            $settings->set('Nodes.' . $family . 'User', $user, $name);
            $settings->set('Nodes.' . $family . 'Pass', $pass, $name);
        }

        // Same "only write if something was actually typed" gate as the
        // Nodes.* block above - an admin who left the Database row blank
        // gets exactly what databaseRows() already falls back to
        // ('mysql'/'', via its own `?? 'mysql'`/`?? ''` defaults), not a
        // stray 'Databases.type' row with four empty siblings.
        if ($dbDatabase !== '' || $dbHost !== '' || $dbPort !== '' || $dbUser !== '' || $dbPass !== '') {
            $settings->set('Databases.type', $dbType, $name);
            $settings->set('Databases.' . $dbType . 'Database', $dbDatabase, $name);
            $settings->set('Databases.' . $dbType . 'Host', $dbHost, $name);
            $settings->set('Databases.' . $dbType . 'Port', $dbPort, $name);
            $settings->set('Databases.' . $dbType . 'User', $dbUser, $name);
            $settings->set('Databases.' . $dbType . 'Pass', $dbPass, $name);
        }

        // Adding the 2nd node is exactly the moment "start automatically
        // once there are 2 nodes with tested connections" (see
        // autoStartCluster()'s own docblock) can first become true - check
        // right here rather than only on the next page load.
        $this->autoStartCluster();

        return $this->response->setJSON(['ok' => true, 'csrf' => $this->csrfPayload()]);
    }

    // "Delete node" - the trash icon under each node's name badge in the
    // Cluster table (see app/Views/Settings/index.php). Mirror image of
    // addNode(): rewrites cluster.nodes in .env with this one entry
    // removed (every OTHER node's baseURL/type/publicKey carried through
    // unchanged, same writeClusterNodesEnv() call addNode() already uses),
    // then purges this node's own Nodes.*/Databases.* rows from the
    // Settings store so nothing orphaned lingers if the same name is ever
    // reused. Removing the LAST remaining node empties cluster.nodes
    // entirely - Config\Cluster::nodesFromEnv() already treats that the
    // same as a fresh install, so the Settings page's own "no cluster
    // configured yet, Import cluster" state (see importCluster()) is
    // exactly what reappears, not a broken one.
    public function deleteNode(): ResponseInterface
    {
        if (! (auth()->user()?->inGroup('superadmin'))) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        $name = (string) $this->request->getPost('node');

        $existing = class_exists(\AdGo\Cluster\Cluster::class) ? (new \AdGo\Cluster\Cluster())->allNodes() : [];
        if (! array_key_exists($name, $existing)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => "Unknown node '{$name}'."]);
        }

        $entries = $existing;
        unset($entries[$name]);
        if (! $this->writeClusterNodesEnv($entries)) {
            return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'error' => 'Could not write .env.']);
        }

        // Propagate the removal to every OTHER node - see
        // Cluster::broadcastNodeDelete()'s own docblock.
        if (class_exists(\AdGo\Cluster\Cluster::class)) {
            (new \AdGo\Cluster\Cluster())->broadcastNodeDelete($name, time());
        }

        $settings = service('settings');
        $settings->forgetMany(array_map(static fn (string $prop): string => 'Nodes.' . $prop, self::NODE_PROPS), $name);
        $settings->forgetMany(array_map(static fn (string $prop): string => 'Databases.' . $prop, self::DATABASE_PROPS), $name);

        return $this->response->setJSON(['ok' => true, 'csrf' => $this->csrfPayload()]);
    }

    // "Delete" button on the Cluster card - the inverse of importCluster():
    // blanks THIS node's own cluster.nodes/thisNode/secretToken/
    // signingPrivateKey in .env (an empty cluster.nodes reads exactly like
    // "no cluster configured yet" to Config\Cluster::nodesFromEnv() - same
    // end state deleteNode()'s own docblock already describes for removing
    // the last remaining node), forgets every node's Nodes.*/Databases.*
    // credential rows, and clears the leftover writable/Cluster/* sync-state
    // files - the same residual-file class of bug InstallCommand::
    // ensureCleanClusterState() exists to prevent on a fresh install (a
    // stale files_manifest.json/node_registry_state.json surviving into a
    // LATER re-import would misinform SyncFilesCommand/the registry-
    // propagation feature about what's actually already synced).
    //
    // Deliberately LOCAL to this node only, same scope as importCluster()
    // itself - unlike deleteNode() (removes ONE peer, called from some
    // OTHER already-configured node), this is "this node leaves the mesh
    // entirely". Every OTHER node keeps its own copy of the registry
    // unless someone separately removes this node from THEIR side too
    // (deleteNode(), or their own next node-registry pull once this node
    // simply stops answering).
    public function resetCluster(): ResponseInterface
    {
        if (! (auth()->user()?->inGroup('superadmin'))) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        $existing = class_exists(\AdGo\Cluster\Cluster::class) ? (new \AdGo\Cluster\Cluster())->allNodes() : [];

        if (! $this->writeEnvLines([
            'cluster.nodes'             => '""',
            'cluster.thisNode'          => '',
            'cluster.secretToken'       => '""',
            'cluster.signingPrivateKey' => '""',
        ])) {
            return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'error' => 'Could not write .env.']);
        }

        $settings = service('settings');
        foreach (array_keys($existing) as $name) {
            $settings->forgetMany(array_map(static fn (string $prop): string => 'Nodes.' . $prop, self::NODE_PROPS), $name);
            $settings->forgetMany(array_map(static fn (string $prop): string => 'Databases.' . $prop, self::DATABASE_PROPS), $name);
        }

        $this->clearClusterStateFiles();

        return $this->response->setJSON(['ok' => true, 'csrf' => $this->csrfPayload()]);
    }

    // Shared by resetCluster() above - see that method's own docblock for
    // why leftover state files must never survive a reset. Mirrors
    // InstallCommand::ensureCleanClusterState() exactly (a second, small
    // copy rather than a shared dependency - app/Commands can't be
    // required from app/Controllers, and this is a handful of lines).
    private function clearClusterStateFiles(): void
    {
        $dir = WRITEPATH . 'Cluster';
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    // Shared row-shape check for importSettings() - returns an error string
    // (or null if the row is fine) rather than throwing, so the caller can
    // fold it straight into its own '{table}.{node}: {error}' message.
    private function invalidImportRow(string $node, mixed $props, array $known, array $allowedProps): ?string
    {
        if (! array_key_exists($node, $known)) {
            return "unknown node '{$node}'.";
        }
        if (! is_array($props)) {
            return 'expected an object of properties.';
        }
        foreach ($props as $prop => $value) {
            if (! in_array($prop, $allowedProps, true)) {
                return "unknown property '{$prop}'.";
            }
            if (! is_scalar($value) && $value !== null) {
                return "property '{$prop}' must be a string.";
            }
        }

        return null;
    }

    public function uploadLogo(): ResponseInterface
    {
        if (! (auth()->user()?->inGroup('superadmin'))) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        $logo = $this->request->getFile('logo');
        if ($logo === null || ! $logo->isValid() || $logo->hasMoved()) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false]);
        }
        $old = setting('Site.logo');
        if ($old && is_file(FCPATH . $old)) {
            @unlink(FCPATH . $old);
        }
        $name = $logo->getRandomName();
        $logo->move(FCPATH . 'uploads/site', $name);
        service('settings')->set('Site.logo', 'uploads/site/' . $name);

        return $this->response->setJSON(['ok' => true, 'path' => 'uploads/site/' . $name, 'csrf' => $this->csrfPayload()]);
    }

    // Thumbnail "Delete" badge next to the logo upload field.
    public function deleteLogo(): ResponseInterface
    {
        if (! (auth()->user()?->inGroup('superadmin'))) {
            return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
        }

        $path = setting('Site.logo');
        if ($path && is_file(FCPATH . $path)) {
            @unlink(FCPATH . $path);
        }
        service('settings')->set('Site.logo', null);

        return $this->response->setJSON(['ok' => true, 'csrf' => $this->csrfPayload()]);
    }
}
