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
    // never overwrites an unsaved keystroke. Rows/fields are updated in
    // place, never added or removed - a node appearing/disappearing from
    // the registry itself already reloads this page through the Import/
    // Add-node/Delete-node flows settings.js already drives, so this poll
    // deliberately stays narrower than that: existing rows only.
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

    function poll() {
        fetch(statusUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                if (data.nodes) applyStatuses(data.nodes);
                if (data.rows) applyRows(data.rows);
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
})();
