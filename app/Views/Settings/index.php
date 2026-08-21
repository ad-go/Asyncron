<?= $this->extend('Layout/app') ?>
<?= $this->section('content') ?>
<div class="page-header d-print-none"><div class="container-xl"><h2 class="page-title"><?= lang('App.settingsMenu') ?></h2></div></div>
<div class="row">
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-body compact-form" id="settings-form"
                 data-endpoint="<?= url_to('SettingsController::update') ?>"
                 data-logo-endpoint="<?= url_to('SettingsController::uploadLogo') ?>"
                 data-logo-delete-endpoint="<?= url_to('SettingsController::deleteLogo') ?>">
                <!-- Reactive form: no save button anywhere here on purpose - every
                     field autosaves via public/assets/settings.js on change/blur.
                     4-column grid, one line (was a 2-column, 2-row grid) - see
                     .compact-form in app.css for the smaller font/padding. -->
                <div class="row">
                    <div class="col-6 col-md-3 mb-2"><label class="form-label"><?= lang('App.title') ?></label>
                        <input type="text" class="form-control form-control-sm" data-field="title" value="<?= esc($siteTitle ?? '') ?>"></div>
                    <div class="col-6 col-md-3 mb-2"><label class="form-label"><?= lang('App.footer') ?></label>
                        <input type="text" class="form-control form-control-sm" data-field="footer" value="<?= esc($siteFooter ?? '') ?>"></div>
                    <div class="col-6 col-md-3 mb-2"><label class="form-label"><?= lang('App.themeColor') ?></label>
                        <select class="form-select form-select-sm" data-field="themeColor">
                            <?php foreach (\App\Controllers\SettingsController::THEME_COLORS as $color) : ?>
                                <option value="<?= esc($color) ?>" <?= ($siteThemeColor ?? 'blue') === $color ? 'selected' : '' ?>><?= lang('App.color' . ucfirst($color)) ?></option>
                            <?php endforeach ?>
                        </select></div>
                    <div class="col-6 col-md-3 mb-2">
                        <label class="form-label"><?= lang('App.logo') ?></label>
                        <!-- Click-or-paste dropzone (see settings.js) instead of a
                             plain <input type=file> - tabindex so it can receive
                             focus (and therefore a clipboard paste event) by
                             clicking anywhere in it, not just the file input. -->
                        <div class="settings-logo-drop" id="settings-logo-drop" tabindex="0" role="button" aria-label="<?= lang('App.logoDropLabel') ?>">
                            <div class="d-flex align-items-center gap-2 <?= empty($siteLogo) ? 'd-none' : '' ?>" id="settings-logo-thumb-wrap">
                                <img src="<?= ! empty($siteLogo) ? esc(base_url($siteLogo)) : '' ?>" alt="" class="logo-thumb rounded" id="settings-logo-thumb">
                                <span class="badge bg-red-lt" id="settings-logo-delete-btn" style="cursor:pointer"><?= lang('App.delete') ?></span>
                            </div>
                            <div id="settings-logo-drop-hint" class="<?= empty($siteLogo) ? '' : 'd-none' ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 18a4.6 4.4 0 0 1 0 -9a5 5 0 0 1 11 -2h1a3.5 3.5 0 0 1 0 7h-1" /><path d="M9 15l3 -3l3 3" /><path d="M12 12l0 9" /></svg>
                                <div class="small text-muted"><?= lang('App.logoDropHint') ?></div>
                            </div>
                            <input type="file" class="d-none" id="settings-logo" accept="image/*">
                        </div>
                    </div>
                </div>
                <span class="badge bg-green-lt d-none" id="settings-saved"><?= lang('App.saved') ?></span>
            </div>
        </div>
    </div>
</div>
<?php
    // Unified 2026-08-21: this card used to be two entirely separate
    // branches - a populated Nodes/Databases table (nodes !== []) or a
    // bare "no cluster configured, Import cluster" placeholder (nodes ===
    // []) with no table at all. Now the table always renders - a fresh,
    // unconfigured install just gets the always-present "add a node" row
    // below pre-filled with what's actually known about the server it's
    // running on (see SettingsController::selfNodePreview()), fully
    // editable like every other field on this page (name included, per an
    // explicit 2026-08-21 request - a separate read-only preview row here
    // before this change was confusing sitting right above an already-
    // editable add-row saying the same thing). Export/Delete only make
    // sense once there's an actual multi-node mesh to export or reset (see
    // index()'s own 'clusterFunctional' docblock) - gated on that, not on
    // whether the table has any rows at all. The bootstrap "Import
    // cluster" flow (importCluster()) only ever accepts an entirely empty
    // registry (see its own docblock - first-run only, not a merge tool),
    // so it's shown in place of the regular per-node Import button exactly
    // while $nodes is still empty, not tied to the 2-node threshold below.
    $selfSuggestName       = $nodes === [] ? $selfNode['name'] : '';
    $selfSuggestUrl        = $nodes === [] ? $selfNode['url'] : '';
    $selfSuggestDbType     = $nodes === [] ? $selfNode['database']['type'] : 'mysql';
    $selfSuggestDbDatabase = $nodes === [] ? $selfNode['database']['database'] : '';
    $selfSuggestDbHost     = $nodes === [] ? $selfNode['database']['host'] : '';
    $selfSuggestDbPort     = $nodes === [] ? $selfNode['database']['port'] : '';
    $selfSuggestDbUser     = $nodes === [] ? $selfNode['database']['user'] : '';
?>
<div class="row mt-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><?= lang('App.clusterTitle') ?></h3>
                <div class="card-actions ms-auto" id="settings-export-import"
                     data-export-endpoint="<?= url_to('SettingsController::exportSettings') ?>"
                     data-import-endpoint="<?= url_to('SettingsController::importSettings') ?>"
                     data-import-strings="<?= esc(json_encode(['success' => lang('App.importSuccess'), 'failed' => lang('App.importFailed')]), 'attr') ?>"
                     data-reset-endpoint="<?= url_to('SettingsController::resetCluster') ?>">
                    <?php if ($clusterFunctional) : ?>
                    <a class="btn btn-sm btn-outline-primary me-2" href="<?= url_to('SettingsController::exportSettings') ?>"><?= lang('App.exportButton') ?></a>
                    <?php endif ?>
                    <?php if ($nodes === []) : ?>
                    <span id="settings-cluster-import"
                          data-import-endpoint="<?= url_to('SettingsController::importCluster') ?>"
                          data-import-strings="<?= esc(json_encode(['failed' => lang('App.importFailed')]), 'attr') ?>">
                        <button type="button" class="btn btn-sm btn-outline-primary me-2" id="settings-import-cluster-btn"><?= lang('App.importClusterButton') ?></button>
                        <input type="file" accept="application/json" class="d-none" id="settings-import-cluster-file">
                        <span class="badge bg-red-lt d-none ms-2" id="settings-import-cluster-error"></span>
                    </span>
                    <?php else : ?>
                    <button type="button" class="btn btn-sm btn-outline-primary me-2" id="settings-import-btn"><?= lang('App.importButton') ?></button>
                    <input type="file" accept="application/json" class="d-none" id="settings-import-file">
                    <?php endif ?>
                    <?php if ($clusterFunctional) : ?>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="settings-reset-cluster-btn"><?= lang('App.resetClusterButton') ?></button>
                    <?php endif ?>
                    <span class="badge bg-red-lt d-none ms-2" id="settings-import-error"></span>
                </div>
            </div>
            <!-- Surfaces importSettings()/importCluster()'s own 'warning'
                 field (thisNode couldn't be auto-detected - see
                 configureClusterIdentity()'s docblock) - persisted across
                 the post-import reload via sessionStorage (see
                 settings.js), since the warning arrives in the SAME
                 response that triggers the reload and would otherwise be
                 gone before there's anywhere left to show it. -->
            <div class="alert alert-warning m-3 mb-0 d-none" id="settings-cluster-warning"></div>
            <div class="table-responsive compact-form">
                <!-- Nodes and Databases merged into one table, grouped BY
                     NODE: each node is one two-row block (a rowspan=2 name
                     cell ties the pair together as a single visual line) -
                     the FTP/SSH connection row on top, the DB credential
                     row directly under it, sharing the URL/Protocol column
                     positions (Database reuses the URL column; Protocol has
                     no DB equivalent, so that cell is just empty on the DB
                     row - the single combined connection test lives on the
                     name badge above instead, see below). Two independent
                     autosave endpoints feed the same table now, so every
                     [data-node][data-prop] field also carries data-row
                     ("node" or "database") - see settings.js's
                     bindClusterAutosave(), which picks data-node-endpoint
                     vs data-database-endpoint per field from that. -->
                <table class="table card-table table-vcenter" id="settings-cluster"
                       data-node-endpoint="<?= url_to('SettingsController::updateNode') ?>"
                       data-database-endpoint="<?= url_to('SettingsController::updateDatabase') ?>"
                       data-node-test-endpoint="<?= url_to('SettingsController::testNode') ?>"
                       data-delete-endpoint="<?= url_to('SettingsController::deleteNode') ?>"
                       data-test-strings="<?= esc(json_encode(['ok' => lang('App.connTestOk'), 'failed' => lang('App.connTestFailed'), 'waitingNat' => lang('App.connTestWaitingNat'), 'waitingUnknown' => lang('App.connTestWaitingUnknown'), 'timeout' => lang('App.connTestTimeout')]), 'attr') ?>">
                    <thead>
                        <tr>
                            <th><?= lang('App.nodeName') ?></th>
                            <th><?= lang('App.nodeType') ?></th>
                            <th><?= lang('App.clusterUrlDb') ?></th>
                            <th><?= lang('App.nodeProtocol') ?></th>
                            <th><?= lang('App.nodeHost') ?></th>
                            <th><?= lang('App.nodePort') ?></th>
                            <th><?= lang('App.nodeUser') ?></th>
                            <th><?= lang('App.nodePass') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($nodes as $name => $node) : ?>
                        <?php
                            // Two independent credential sets per node (FTP/FTPS and
                            // SSH/SCP - see SettingsController::NODE_PROPS's own
                            // docblock) - both loaded into data-ftp-*/data-ssh-*
                            // attributes below so settings.js can swap the visible
                            // Host/Port/User/Pass fields between them the instant the
                            // Protocol dropdown changes, no round trip needed. Which
                            // one starts visible just follows the currently stored
                            // protocol.
                            $family     = in_array($node['protocol'], ['SSH', 'SCP'], true) ? 'ssh' : 'ftp';
                            $activeProp = ['host' => $family . 'Host', 'port' => $family . 'Port', 'user' => $family . 'User', 'pass' => $family . 'Pass'];
                            // Same node key in both tables (nodeRows()/databaseRows()
                            // both iterate the SAME registry) - null only if the
                            // registry and Settings store ever disagree, which
                            // databaseRows() never actually lets happen.
                            $database   = $databases[$name] ?? null;
                            $dbType     = $database['type'] ?? 'mysql';
                            $dbProp     = ['database' => $dbType . 'Database', 'host' => $dbType . 'Host', 'port' => $dbType . 'Port', 'user' => $dbType . 'User', 'pass' => $dbType . 'Pass'];
                        ?>
                        <tr data-ftp-host="<?= esc($node['ftpHost']) ?>" data-ftp-port="<?= esc($node['ftpPort']) ?>" data-ftp-user="<?= esc($node['ftpUser']) ?>" data-ftp-pass="<?= esc($node['ftpPass']) ?>"
                            data-ssh-host="<?= esc($node['sshHost']) ?>" data-ssh-port="<?= esc($node['sshPort']) ?>" data-ssh-user="<?= esc($node['sshUser']) ?>" data-ssh-pass="<?= esc($node['sshPass']) ?>">
                            <td rowspan="2" class="align-middle">
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <?php if ($name === $thisNode) : ?>
                                    <!-- This node's own row - no test trigger,
                                         testing a connection from a node to
                                         itself is meaningless (see
                                         SettingsController::testNode()'s own
                                         docblock). bg-secondary-lt (not the
                                         clickable bg-blue-lt every other
                                         row's badge uses) is the visual cue
                                         that this one doesn't do anything. -->
                                    <span class="badge bg-secondary-lt" title="<?= lang('App.thisNodeBadge') ?>"><?= esc($name) ?></span>
                                    <?php else : ?>
                                    <span class="badge bg-blue-lt" style="cursor:pointer" data-test-conn="<?= esc($name) ?>"><?= esc($name) ?></span>
                                    <?php endif ?>
                                    <!-- Same Tabler outline "trash" icon already used by the
                                         Users page's own delete button (see public/assets/
                                         users.js's ICON_DELETE) - inlined SVG, not an icon-font
                                         dependency. text-red (not a filled danger button) reads
                                         as "destructive but secondary" next to the name badge,
                                         same reasoning users.js's own docblock gives for its icon. -->
                                    <span class="text-red" style="cursor:pointer" data-delete-node="<?= esc($name) ?>" title="<?= lang('App.deleteNode') ?>" aria-label="<?= lang('App.deleteNode') ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" /></svg>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <!-- Fixed width (not the select's own auto/100% width) so
                                     this column matches the Databases row's Type select
                                     below it exactly, regardless of either row's currently
                                     selected option text ("Direct"/"NAT" here vs "postgres"/
                                     "sqlite3"/etc there - see DATABASE_TYPES). -->
                                <select class="form-select form-select-sm" style="width:8em;text-align:left" data-row="node" data-node="<?= esc($name) ?>" data-prop="type">
                                    <option value="nat" <?= $node['type'] === 'nat' ? 'selected' : '' ?>><?= lang('App.nodeTypeNat') ?></option>
                                    <option value="local" <?= $node['type'] === 'local' ? 'selected' : '' ?>><?= lang('App.nodeTypeLocal') ?></option>
                                    <option value="public" <?= $node['type'] === 'public' ? 'selected' : '' ?>><?= lang('App.nodeTypeDirect') ?></option>
                                </select>
                            </td>
                            <td><input type="text" class="form-control form-control-sm" data-row="node" data-node="<?= esc($name) ?>" data-prop="url" value="<?= esc($node['url']) ?>"></td>
                            <td>
                                <!-- Every protocol actually used across this cluster's real
                                     nodes (see CI4cluster.asc) - FTP (upz), explicit FTPS/AUTH
                                     TLS (beta, h1q), plus SSH/SCP (h1q, bak, res, upz - see
                                     data-ssh-* above) - not generic placeholder labels.
                                     data-protocol-select marks this for settings.js's
                                     family-swap handler, separate from the plain autosave
                                     every other [data-node][data-prop] select/input already gets. -->
                                <select class="form-select form-select-sm" data-row="node" data-node="<?= esc($name) ?>" data-prop="protocol" data-protocol-select>
                                    <?php foreach (\App\Controllers\SettingsController::NODE_PROTOCOLS as $protocol) : ?>
                                        <option value="<?= esc($protocol) ?>" <?= $node['protocol'] === $protocol ? 'selected' : '' ?>><?= esc($protocol) ?></option>
                                    <?php endforeach ?>
                                </select>
                            </td>
                            <td><input type="text" class="form-control form-control-sm" data-row="node" data-node="<?= esc($name) ?>" data-prop="<?= $activeProp['host'] ?>" data-field-role="host" value="<?= esc($node[$activeProp['host']]) ?>"></td>
                            <td><input type="number" class="form-control form-control-sm" style="width:5.5em" data-row="node" data-node="<?= esc($name) ?>" data-prop="<?= $activeProp['port'] ?>" data-field-role="port" value="<?= esc($node[$activeProp['port']]) ?>"></td>
                            <td><input type="text" class="form-control form-control-sm" data-row="node" data-node="<?= esc($name) ?>" data-prop="<?= $activeProp['user'] ?>" data-field-role="user" value="<?= esc($node[$activeProp['user']]) ?>"></td>
                            <td><input type="password" class="form-control form-control-sm" data-row="node" data-node="<?= esc($name) ?>" data-prop="<?= $activeProp['pass'] ?>" data-field-role="pass" value="<?= esc($node[$activeProp['pass']]) ?>"></td>
                        </tr>
                        <?php if ($database !== null) : ?>
                        <tr class="border-top-0"<?php foreach (\App\Controllers\SettingsController::DATABASE_TYPES as $t) : ?> data-<?= $t ?>-host="<?= esc($database[$t . 'Host']) ?>" data-<?= $t ?>-port="<?= esc($database[$t . 'Port']) ?>" data-<?= $t ?>-user="<?= esc($database[$t . 'User']) ?>" data-<?= $t ?>-pass="<?= esc($database[$t . 'Pass']) ?>" data-<?= $t ?>-database="<?= esc($database[$t . 'Database']) ?>"<?php endforeach ?>>
                                <!-- No Name <td> here - the row above's rowspan=2 already
                                     covers this line, which is the point: one badge, one
                                     "line" per node, two stacked rows underneath it. -->
                                <td>
                                    <select class="form-select form-select-sm" style="width:8em;text-align:left" data-row="database" data-node="<?= esc($name) ?>" data-prop="type" data-type-select>
                                        <?php foreach (\App\Controllers\SettingsController::DATABASE_TYPES as $t) : ?>
                                            <option value="<?= esc($t) ?>" <?= $dbType === $t ? 'selected' : '' ?>><?= esc($t) ?></option>
                                        <?php endforeach ?>
                                    </select>
                                </td>
                                <td><input type="text" class="form-control form-control-sm" data-row="database" data-node="<?= esc($name) ?>" data-prop="<?= $dbProp['database'] ?>" data-field-role="database" value="<?= esc($database[$dbProp['database']]) ?>"></td>
                                <!-- The DB row has no Protocol field, and no
                                     test badge of its own - the name badge
                                     above tests this row's connection too
                                     now (see testNode()'s own docblock). -->
                                <td></td>
                                <td><input type="text" class="form-control form-control-sm" data-row="database" data-node="<?= esc($name) ?>" data-prop="<?= $dbProp['host'] ?>" data-field-role="host" value="<?= esc($database[$dbProp['host']]) ?>"></td>
                                <td><input type="number" class="form-control form-control-sm" style="width:5.5em" data-row="database" data-node="<?= esc($name) ?>" data-prop="<?= $dbProp['port'] ?>" data-field-role="port" value="<?= esc($database[$dbProp['port']]) ?>"></td>
                                <td><input type="text" class="form-control form-control-sm" data-row="database" data-node="<?= esc($name) ?>" data-prop="<?= $dbProp['user'] ?>" data-field-role="user" value="<?= esc($database[$dbProp['user']]) ?>"></td>
                                <td><input type="password" class="form-control form-control-sm" data-row="database" data-node="<?= esc($name) ?>" data-prop="<?= $dbProp['pass'] ?>" data-field-role="pass" value="<?= esc($database[$dbProp['pass']]) ?>"></td>
                        </tr>
                        <?php endif ?>
                        <?php endforeach ?>
                        <!-- Blank "add a node" row - a two-row block, same
                             shape as every real node above, so every field
                             (including the name) is editable from the
                             start, not a cut-down single line. Submitted as
                             ONE create call (settings.js's addNodeBtn
                             handler -> SettingsController::addNode(), which
                             now also accepts the db* fields below) rather
                             than autosaved per field like every other row
                             here - a brand-new name isn't "known" to
                             updateNode()/updateDatabase() (both check
                             array_key_exists($node, Cluster::allNodes()))
                             until cluster.nodes itself already lists it, so
                             there's nothing for per-field autosave to
                             attach to before that first happens. Host/Port/
                             User/Pass on the node row are ONE plain set,
                             not an ftp*/ssh* pair with a live swap like the
                             real rows - nothing stored yet to swap between;
                             the chosen Protocol's family is resolved
                             server-side, once, at creation. Pre-filled from
                             SettingsController::selfNodePreview() while the
                             registry is still empty (see index()'s own
                             'selfNode' docblock) - blank otherwise, same as
                             before. -->
                        <tr id="settings-add-node-row" data-add-endpoint="<?= url_to('SettingsController::addNode') ?>"
                            data-add-strings="<?= esc(json_encode(['failed' => lang('App.addNodeFailed')]), 'attr') ?>">
                            <td rowspan="2" class="align-middle">
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control form-control-sm" id="settings-add-node-name" placeholder="<?= lang('App.addNodeNamePlaceholder') ?>" value="<?= esc($selfSuggestName) ?>">
                                    <button class="btn btn-outline-success" type="button" id="settings-add-node-btn">+</button>
                                </div>
                                <span class="badge bg-red-lt d-none mt-1" id="settings-add-node-error"></span>
                            </td>
                            <td>
                                <select class="form-select form-select-sm" style="width:8em;text-align:left" id="settings-add-node-type">
                                    <option value="local"><?= lang('App.nodeTypeLocal') ?></option>
                                    <option value="public" selected><?= lang('App.nodeTypeDirect') ?></option>
                                    <option value="nat"><?= lang('App.nodeTypeNat') ?></option>
                                </select>
                            </td>
                            <td><input type="text" class="form-control form-control-sm" id="settings-add-node-url" placeholder="<?= lang('App.addNodeUrlPlaceholder') ?>" value="<?= esc($selfSuggestUrl) ?>"></td>
                            <td>
                                <select class="form-select form-select-sm" id="settings-add-node-protocol">
                                    <?php foreach (\App\Controllers\SettingsController::NODE_PROTOCOLS as $protocol) : ?>
                                        <option value="<?= esc($protocol) ?>"><?= esc($protocol) ?></option>
                                    <?php endforeach ?>
                                </select>
                            </td>
                            <td><input type="text" class="form-control form-control-sm" id="settings-add-node-host"></td>
                            <td><input type="number" class="form-control form-control-sm" style="width:5.5em" id="settings-add-node-port"></td>
                            <td><input type="text" class="form-control form-control-sm" id="settings-add-node-user"></td>
                            <td><input type="password" class="form-control form-control-sm" id="settings-add-node-pass"></td>
                        </tr>
                        <tr class="border-top-0">
                            <td>
                                <select class="form-select form-select-sm" style="width:8em;text-align:left" id="settings-add-node-dbType">
                                    <?php foreach (\App\Controllers\SettingsController::DATABASE_TYPES as $t) : ?>
                                        <option value="<?= esc($t) ?>" <?= $selfSuggestDbType === $t ? 'selected' : '' ?>><?= esc($t) ?></option>
                                    <?php endforeach ?>
                                </select>
                            </td>
                            <td><input type="text" class="form-control form-control-sm" id="settings-add-node-dbDatabase" value="<?= esc($selfSuggestDbDatabase) ?>"></td>
                            <!-- The DB row has no Protocol field, same as every
                                 real node's own DB row above. -->
                            <td></td>
                            <td><input type="text" class="form-control form-control-sm" id="settings-add-node-dbHost" value="<?= esc($selfSuggestDbHost) ?>"></td>
                            <td><input type="number" class="form-control form-control-sm" style="width:5.5em" id="settings-add-node-dbPort" value="<?= esc($selfSuggestDbPort) ?>"></td>
                            <td><input type="text" class="form-control form-control-sm" id="settings-add-node-dbUser" value="<?= esc($selfSuggestDbUser) ?>"></td>
                            <td><input type="password" class="form-control form-control-sm" id="settings-add-node-dbPass"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php if ($nodes !== []) : ?>
<!-- Opened by clicking a node-name badge in the Cluster table above (see
     settings.js) - tests that row's file-sync connection AND its
     configured database connection together (see
     SettingsController::testNode()'s own docblock), shown as two result
     lines in the one modal below rather than one line per separate test,
     same "test the row's currently-saved credentials live" idea as this
     project's own one-off verification scripts used by hand throughout
     this project's setup, just permanent and in-app now. -->
<div class="modal modal-blur fade" id="conn-test-modal" tabindex="-1"
     data-test-result-endpoint="<?= url_to('SettingsController::testResult') ?>">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="conn-test-modal-title"><?= lang('App.connTestTitle') ?></h5>
                <!-- Live stopwatch, started the instant the badge is
                     clicked and frozen the instant a result (or error/
                     timeout) lands - see settings.js's startConnTimer()/
                     stopConnTimer(). Lives in the header (not inside the
                     loading/result toggle below) so it stays visible and
                     keeps counting through the whole request, NAT-relay
                     polling included, then still shows the final total
                     once the result's own two sections replace the
                     spinner - answers "how long did this actually take"
                     for the round trip as a whole, which neither
                     section's own server-measured `ms` alone captures. -->
                <span class="text-muted small font-monospace ms-auto me-2" id="conn-test-modal-elapsed" style="font-variant-numeric:tabular-nums">0.000s</span>
                <button type="button" class="btn-close" id="conn-test-modal-close"></button></div>
            <div class="modal-body">
                <div id="conn-test-modal-loading" class="text-center py-3">
                    <div class="spinner-border text-blue" role="status"></div>
                    <div id="conn-test-modal-waiting" class="text-muted small mt-2 d-none"></div>
                </div>
                <!-- Whole-request failure (network error, or the NAT-relay
                     poll giving up - see pollForResult() in settings.js) -
                     there's no per-capability result to show a section for
                     in either case, just one message. -->
                <div id="conn-test-modal-error" class="text-red small d-none"></div>
                <!-- Two identically-shaped sections, one per sub-result
                     ('node' = file-sync connection, 'database' = the
                     configured DB connection) - settings.js's
                     showConnResult() fills both from the same response.
                     data-conn-section marks each for that lookup instead
                     of hardcoding four id's worth of DOM plumbing per
                     section. -->
                <div id="conn-test-modal-result" class="d-none">
                    <div class="mb-3" data-conn-section="node">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="text-muted small text-uppercase" style="min-width:6em"><?= lang('App.connTestNodeLabel') ?></span>
                            <span class="badge" data-conn-badge></span>
                            <span data-conn-summary></span>
                        </div>
                        <pre class="mb-0 ps-1" style="white-space:pre-wrap;word-break:break-word;" data-conn-detail></pre>
                    </div>
                    <div data-conn-section="database">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="text-muted small text-uppercase" style="min-width:6em"><?= lang('App.connTestDatabaseLabel') ?></span>
                            <span class="badge" data-conn-badge></span>
                            <span data-conn-summary></span>
                        </div>
                        <pre class="mb-0 ps-1" style="white-space:pre-wrap;word-break:break-word;" data-conn-detail></pre>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn" id="conn-test-modal-ok"><?= lang('App.close') ?></button>
            </div>
        </div>
    </div>
</div>
<!-- Confirm-modal for the trash icon under each node's name badge, same
     "native confirm()/alert() blocks the page's JS thread and freezes a
     CDP-controlled browser automation session" reasoning documented on
     the Users page's own delete-user-modal (see public/assets/users.js)
     - never a browser-native dialog anywhere in this app. -->
<div class="modal modal-blur fade" id="delete-node-modal" tabindex="-1"
     data-delete-strings="<?= esc(json_encode(['failed' => lang('App.deleteNodeFailed')]), 'attr') ?>">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><?= lang('App.confirmDeleteNodeTitle') ?></h5>
                <button type="button" class="btn-close" id="delete-node-modal-close"></button></div>
            <div class="modal-body">
                <?= lang('App.confirmDeleteNodeBody') ?>
                <div class="text-red small mt-2 d-none" id="delete-node-modal-error"></div>
            </div>
            <div class="modal-footer">
                <button class="btn" id="delete-node-modal-cancel"><?= lang('App.cancel') ?></button>
                <button class="btn btn-danger" id="delete-node-modal-confirm"><?= lang('App.delete') ?></button>
            </div>
        </div>
    </div>
</div>
<!-- Confirm-modal for the Cluster card's "Delete" button (resetCluster()) -
     same never-a-native-dialog reasoning as delete-node-modal above, just
     for the whole-mesh-membership reset instead of one peer. -->
<div class="modal modal-blur fade" id="reset-cluster-modal"
     data-reset-strings="<?= esc(json_encode(['failed' => lang('App.resetClusterFailed')]), 'attr') ?>">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><?= lang('App.confirmResetClusterTitle') ?></h5>
                <button type="button" class="btn-close" id="reset-cluster-modal-close"></button></div>
            <div class="modal-body">
                <?= lang('App.confirmResetClusterBody') ?>
                <div class="text-red small mt-2 d-none" id="reset-cluster-modal-error"></div>
            </div>
            <div class="modal-footer">
                <button class="btn" id="reset-cluster-modal-cancel"><?= lang('App.cancel') ?></button>
                <button class="btn btn-danger" id="reset-cluster-modal-confirm"><?= lang('App.resetClusterButton') ?></button>
            </div>
        </div>
    </div>
</div>
<?php endif ?>
<script src="<?= base_url('assets/settings.js') ?>" defer></script>
<?= $this->endSection() ?>
