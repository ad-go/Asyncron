(function () {
    'use strict';

    // #settings-cluster (the Cluster table itself), not #node-status-list -
    // the table needs a live poll even with zero peers (no LED card at all
    // yet, just this node's own row), while the LED list only ever exists
    // once there's at least one peer to show. See SettingsController::
    // nodeStatus()'s own docblock for the combined {nodes, rows} shape this
    // polls - 'rows' is mergedNodeRows(), the SAME data index() already
    // renders server-side on first load and exportSettings() already
    // builds for the Export button, extended here to a third caller
    // instead of a fourth copy of that merge.
    var table = document.getElementById('settings-cluster');
    if (!table) return;

    var statusUrl = table.dataset.statusUrl;
    var listEl = document.getElementById('node-status-list');

    // Same --node-led-* custom properties app.css defines for the
    // Dashboard's own Network graph/legend (see dashboard-network.js's own
    // NODE_LED_COLORS) - read directly here rather than via that script's
    // window global, since this page never loads it.
    var rootStyle = getComputedStyle(document.documentElement);
    var COLORS = {
        ok:   rootStyle.getPropertyValue('--node-led-ok').trim() || '#2fb344',
        bad:  rootStyle.getPropertyValue('--node-led-bad').trim() || '#d63939',
        idle: rootStyle.getPropertyValue('--node-led-idle').trim() || '#8c959e',
    };

    // Only the dots' own colors change poll to poll, not the peer list
    // itself (adding/removing a node already reloads this page - see
    // settings.js's own importSuccess/addNode handling) - updating each
    // existing [data-led] in place is simpler than rebuilding the list
    // from scratch every 5s for a set of rows that isn't actually moving.
    function applyStatuses(statuses) {
        if (!listEl) return;
        var dots = listEl.querySelectorAll('[data-led]');
        for (var i = 0; i < dots.length; i++) {
            var name = dots[i].getAttribute('data-led');
            var status = statuses[name];
            if (status) dots[i].style.background = COLORS[status] || COLORS.idle;
        }
    }

    // Every ftp*/ssh* (Nodes row) or {type}* (Databases row) credential
    // this table can ever show, mirroring app/Views/Settings/index.php's
    // own data-ftp-*/data-ssh-*/data-{type}-* attributes exactly - kept
    // fresh on EVERY row regardless of which family is currently visible,
    // so a family the admin isn't even looking at right now (say, this
    // row is on FTP - its ssh* fields are hidden) doesn't go stale and
    // then resurface outdated the next time someone flips the Protocol/
    // Type dropdown here (settings.js's own bindFamilySwap() reads these
    // straight off the <tr>'s own dataset, not a fresh fetch).
    var NODE_FAMILIES = ['ftp', 'ssh'];
    var DATABASE_TYPES = ['mysql', 'postgres', 'sqlite3', 'oci8', 'sqlsrv'];
    var CRED_FIELDS = ['Host', 'Port', 'User', 'Pass'];

    function refreshRowDataset(tr, row, freshRow) {
        if (row === 'node') {
            NODE_FAMILIES.forEach(function (family) {
                CRED_FIELDS.forEach(function (field) {
                    var prop = family + field;
                    if (freshRow[prop] !== undefined) tr.dataset[family + field] = freshRow[prop];
                });
            });
        } else {
            var db = freshRow.database || {};
            DATABASE_TYPES.forEach(function (type) {
                CRED_FIELDS.concat(['Database']).forEach(function (field) {
                    var prop = type + field;
                    if (db[prop] !== undefined) tr.dataset[type + field] = db[prop];
                });
            });
        }
    }

    // Updates every [data-node][data-prop] field with whatever the server
    // currently has - EXCEPT the one field the admin is actively typing
    // into right now (document.activeElement), so a poll landing mid-edit
    // never overwrites an unsaved keystroke. Fields are updated in place,
    // never added or removed - a node appearing/disappearing from the
    // registry is a structurally different change (a whole new/gone row,
    // not just a value) and reloadIfNodeSetChanged() below already
    // catches that separately, BEFORE this ever runs - see its own
    // docblock. This function only ever runs against a node set that's
    // already confirmed unchanged from what's currently rendered.
    function applyRows(rows) {
        var seenRows = {};
        table.querySelectorAll('[data-node][data-prop]').forEach(function (el) {
            var name = el.dataset.node;
            var freshRow = rows[name];
            if (!freshRow) return;

            var tr = el.closest('tr');
            if (tr && !seenRows[name + ':' + el.dataset.row]) {
                seenRows[name + ':' + el.dataset.row] = true;
                refreshRowDataset(tr, el.dataset.row, freshRow);
            }

            if (el === document.activeElement) return;
            var source = el.dataset.row === 'database' ? (freshRow.database || {}) : freshRow;
            var value = source[el.dataset.prop];
            if (value !== undefined && el.value !== value) el.value = value;
        });
    }

    // Which nodes are CURRENTLY rendered, captured once at load and kept
    // in sync with every reload this triggers below - deliberately not
    // re-derived from the DOM on every poll (a node's OWN <tr> carries
    // several [data-node] elements - name badge, node row fields,
    // database row fields - re-scanning would just be re-deriving the
    // same dedup work reloadIfNodeSetChanged() already does once here).
    var knownNodeNames = [];
    table.querySelectorAll('[data-node]').forEach(function (el) {
        if (knownNodeNames.indexOf(el.dataset.node) === -1) knownNodeNames.push(el.dataset.node);
    });
    knownNodeNames.sort();

    // The registry itself changing (a node added/removed) is a
    // structural change - a whole new/gone <tr> pair, not just a value -
    // that applyRows() above deliberately never attempts to replicate in
    // JS (this table's real markup - rowspan pairs, family-specific
    // field visibility, the delete icon, ...) - a full reload is what
    // settings.js's own Import/Add-node/Delete-node handlers already do
    // for exactly this reason when THIS tab drives the change; this
    // extends that to changes arriving from elsewhere (another admin's
    // tab, or the registry syncing in a peer's own change) instead of
    // silently drifting out of sync until someone happens to refresh.
    // Skipped for one poll (not indefinitely - the very next tick tries
    // again) while the admin is actively focused inside this table, so a
    // reload never discards an in-progress, unsaved edit.
    function reloadIfNodeSetChanged(rows) {
        var fresh = Object.keys(rows).sort();
        var changed = fresh.length !== knownNodeNames.length || fresh.some(function (name, i) { return name !== knownNodeNames[i]; });
        if (!changed) return false;
        if (table.contains(document.activeElement)) return false;
        location.reload();

        return true;
    }

    function poll() {
        fetch(statusUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                if (data.nodes) applyStatuses(data.nodes);
                if (data.rows) {
                    if (reloadIfNodeSetChanged(data.rows)) return;
                    applyRows(data.rows);
                }
            })
            .catch(function () { /* transient network hiccup - next poll retries */ });
    }

    setInterval(poll, 5000);

    // "Restart cluster" icon (see app/Views/Settings/index.php's own
    // docblock on it, and SettingsController::restartCluster()'s - closes
    // the README "Not built yet" gap between testing ONE peer's own badge
    // and actually doing something cluster-wide). One click, one bounded
    // request (server-side budget keeps this well under any host's real
    // max_execution_time - see that controller method's own docblock) -
    // the spin class is purely visual feedback for that single request,
    // not a poll of its own; applyStatuses() above already refreshes the
    // LEDs a few seconds later once sync/realign actually change
    // anything.
    var restartBtn = document.getElementById('settings-restart-cluster-btn');
    var restartResult = document.getElementById('settings-restart-cluster-result');
    if (restartBtn && restartResult) {
        var restartEndpoint = restartBtn.dataset.restartEndpoint;
        var restartStrings = JSON.parse(restartBtn.dataset.restartStrings || '{}');
        var restartIcon = restartBtn.querySelector('svg');

        function showRestartResult(text, isError) {
            restartResult.textContent = text;
            restartResult.classList.remove('d-none');
            restartResult.classList.toggle('text-red', !!isError);
        }

        restartBtn.addEventListener('click', function () {
            if (restartBtn.classList.contains('disabled')) return;
            restartBtn.classList.add('disabled');
            if (restartIcon) restartIcon.style.animation = 'spinner-border 0.75s linear infinite';
            restartResult.classList.add('d-none');

            var body = new URLSearchParams();
            if (window.CI4_CSRF) body.set(window.CI4_CSRF.name, window.CI4_CSRF.hash);

            // Reads the body as TEXT first, not straight r.json() - found
            // live 2026-08-22: a server-side crash (an uncaught exception
            // mid-response - see SettingsController::restartCluster()'s
            // own per-peer try/catch, added for exactly this) can come
            // back as a 500 with an EMPTY body, which r.json() rejects
            // with its own parse error, landing in .catch() below with no
            // detail at all ("Restart failed: " and nothing after the
            // colon - not useful). Falling back to the HTTP status code
            // when the body isn't valid JSON means there's always SOME
            // concrete detail to show, even for a failure mode this
            // client-side code was never told about.
            fetch(restartEndpoint, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) {
                    return r.text().then(function (text) {
                        var data = null;
                        try { data = JSON.parse(text); } catch (e) { /* handled via the null fallback below */ }

                        return { status: r.status, data: data };
                    });
                })
                .then(function (result) {
                    var data = result.data;
                    if (window.syncCsrf && data) window.syncCsrf(data);
                    if (!data || !data.ok) {
                        var detail = (data && data.error) || ('HTTP ' + result.status);
                        showRestartResult((restartStrings.failed || '{0}').replace('{0}', detail), true);

                        return;
                    }
                    var names = Object.keys(data.tested || {});
                    var ok = names.filter(function (n) { return data.tested[n].ok === true; }).length;
                    var pending = names.filter(function (n) { return data.tested[n].pending; }).length;
                    var msg = (restartStrings.done || '{0}/{1}/{2}')
                        .replace('{0}', names.length)
                        .replace('{1}', ok)
                        .replace('{2}', pending);
                    showRestartResult(msg, false);
                })
                .catch(function (e) {
                    showRestartResult((restartStrings.failed || '{0}').replace('{0}', (e && e.message) || ''), true);
                })
                .finally(function () {
                    restartBtn.classList.remove('disabled');
                    if (restartIcon) restartIcon.style.animation = '';
                });
        });
    }

    // Plain autosave-on-change checkbox, POSTing {enabled: '1'|'0'} to its
    // own data-endpoint - shared by "Settings sync" and "Production sync"
    // below (SettingsController::updateSettingsSync()/updateProductionSync(),
    // DbSyncSchema::settingsSyncEnabled()/productionSyncEnabled()'s own
    // docblocks for exactly what each gates). Reverts the visible checkbox
    // state on any failure so it never silently claims a state the server
    // didn't actually save.
    function wireSyncToggle(id) {
        var toggle = document.getElementById(id);
        if (!toggle) return;

        toggle.addEventListener('change', function () {
            var enabled = toggle.checked;
            toggle.disabled = true;

            var body = new URLSearchParams();
            body.set('enabled', enabled ? '1' : '0');
            if (window.CI4_CSRF) body.set(window.CI4_CSRF.name, window.CI4_CSRF.hash);

            fetch(toggle.dataset.endpoint, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (window.syncCsrf) window.syncCsrf(data);
                    if (!data || !data.ok) {
                        toggle.checked = !enabled;
                    }
                })
                .catch(function () {
                    toggle.checked = !enabled;
                })
                .finally(function () {
                    toggle.disabled = false;
                });
        });
    }

    wireSyncToggle('settings-sync-toggle');
    wireSyncToggle('production-sync-toggle');
    wireSyncToggle('production-source-node-toggle');

    // "Source node" can't be on while "Production sync" is off - the view
    // already renders it `disabled` server-side on first load (see
    // Settings/index.php), this just keeps that live across a toggle
    // flip with no reload. Setting .checked directly (not .click()) fires
    // no 'change' event, so this never triggers the autosave POST
    // wireSyncToggle() just wired up above - flipping Production sync
    // off must never itself overwrite the stored Source-node preference,
    // only hide/disable it (see DbSyncSchema::
    // productionSourceNodeEnabled()'s own docblock: the read side already
    // refuses to report true while sync is off, so nothing here needs to
    // persist a change for that to hold). The toggle's own persisted
    // value is remembered in a data attribute so re-enabling Production
    // sync restores it instead of defaulting to unchecked.
    var prodSyncToggle = document.getElementById('production-sync-toggle');
    var sourceNodeToggle = document.getElementById('production-source-node-toggle');
    if (prodSyncToggle && sourceNodeToggle) {
        sourceNodeToggle.dataset.persistedChecked = sourceNodeToggle.checked ? '1' : '0';
        // Keeps the remembered value current when the admin actually
        // changes it themselves (a real 'change' event, unlike the
        // programmatic .checked sets below) - otherwise a later
        // sync-off-then-on cycle would restore the PAGE-LOAD value
        // instead of whatever they most recently saved.
        sourceNodeToggle.addEventListener('change', function () {
            sourceNodeToggle.dataset.persistedChecked = sourceNodeToggle.checked ? '1' : '0';
        });
        prodSyncToggle.addEventListener('change', function () {
            if (prodSyncToggle.checked) {
                sourceNodeToggle.disabled = false;
                sourceNodeToggle.checked = sourceNodeToggle.dataset.persistedChecked === '1';
            } else {
                sourceNodeToggle.disabled = true;
                sourceNodeToggle.checked = false;
            }
        });
    }
})();
