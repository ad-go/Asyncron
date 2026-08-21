(function () {
    'use strict';

    var listEl = document.getElementById('node-status-list');
    if (!listEl) return;

    var COLORS = { ok: '#2fb344', bad: '#d63939', idle: '#8c959e' };

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Composite health, same rule dashboard.php's own initial server-side
    // render already applies (see that view's $ledColor) - kept in sync
    // intentionally so the list never "flips" the moment the first 5s poll
    // replaces the page-load snapshot. Checks FOUR independent signals, not
    // just the file-sync ok/error dashboard-network.js/dashboard-node-tree.js
    // color their own graph/tree with:
    // - file sync (node.lastSyncOk for a public peer, "has it ever pushed
    //   in" for a NAT one - same as those two scripts' own healthColor())
    // - SSH reachability (node.sshOk - writable/Cluster/ssh_connections.json)
    // - DB sync (node.dbSyncOk - writable/Cluster/db_sync_log.json)
    // - stuck/failing queue jobs (node.queueFailed - the 'cluster' DB
    //   group's own queue_jobs_failed table, i.e. writable/Cluster/
    //   cluster.db itself, not a JSON log)
    // ANY explicit failure among these marks the peer red even if another
    // signal looks fine; idle only when NONE of the four have reported
    // anything at all yet.
    function healthColor(node) {
        var isNat  = node.type === 'nat';
        var fileOk = isNat ? (node.lastPushInAt !== null) : node.lastSyncOk;

        if (fileOk === false || node.sshOk === false || node.dbSyncOk === false || node.queueFailed > 0) {
            return COLORS.bad;
        }
        if (fileOk === true || node.sshOk === true || node.dbSyncOk === true) {
            return COLORS.ok;
        }

        return COLORS.idle;
    }

    function render(info) {
        var names = Object.keys(info.nodes);
        listEl.innerHTML = names.map(function (name) {
            var node = info.nodes[name];

            return '<div class="d-flex align-items-center gap-2 py-1">' +
                '<span class="legend-dot" style="background:' + healthColor(node) + '"></span>' +
                '<span class="text-truncate">' + escapeHtml(name) + '</span>' +
                '</div>';
        }).join('');
    }

    // Same ordering caveat dashboard-node-tree.js's own comment explains -
    // dashboard-network.js (loaded before this script) broadcasts its
    // INITIAL payload synchronously as part of its own top-level execution,
    // which finishes before this script's addEventListener below ever runs.
    // Reading #net-graph's own data-network directly for the first render
    // sidesteps that; the event listener only needs to cover updates from
    // the 5s poll onward, which is also what makes this list "responsive"
    // rather than a page-load-only snapshot.
    var netGraph = document.getElementById('net-graph');
    if (netGraph && netGraph.dataset.network) {
        render(JSON.parse(netGraph.dataset.network));
    }

    window.addEventListener('cluster:network-info', function (e) {
        render(e.detail);
    });
})();
