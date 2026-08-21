(function () {
    'use strict';

    var listEl = document.getElementById('node-status-list');
    if (!listEl) return;

    var statusUrl = listEl.dataset.statusUrl;

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
        var dots = listEl.querySelectorAll('[data-led]');
        for (var i = 0; i < dots.length; i++) {
            var name = dots[i].getAttribute('data-led');
            var status = statuses[name];
            if (status) dots[i].style.background = COLORS[status] || COLORS.idle;
        }
    }

    function poll() {
        fetch(statusUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (data && data.nodes) applyStatuses(data.nodes);
            })
            .catch(function () { /* transient network hiccup - next poll retries */ });
    }

    setInterval(poll, 5000);
})();
