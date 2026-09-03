(function () {
    'use strict';

    var container = document.getElementById('production-tree');
    if (!container || typeof echarts === 'undefined') return;

    var database   = container.dataset.database;
    var sizeHuman  = container.dataset.sizeHuman;
    var tables     = JSON.parse(container.dataset.tables);
    var strings    = JSON.parse(container.dataset.strings);
    var tableNames = Object.keys(tables);

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Match the legend dots rendered next to this chart in the partial -
    // a table's color here is its own syncMode, not a health/status
    // signal like the node tree's colors are (see dashboard-node-tree.js),
    // so it's kept local rather than shared via window.
    var MODE_COLORS = { merge: '#2fb344', 'source-only': '#4299e1', none: '#adb5bd' };
    var MODE_LABELS = { merge: 'modeMerge', 'source-only': 'modeSourceOnly', none: 'modeNone' };

    var children = tableNames.map(function (name) {
        var t = tables[name];
        return {
            name: name,
            value: t,
            itemStyle: { color: MODE_COLORS[t.syncMode] || MODE_COLORS.none },
            symbolSize: 14,
        };
    });

    var root = {
        name: database,
        value: null,
        itemStyle: { color: '#206bc4' },
        symbolSize: 20,
        children: children,
    };

    var chart = echarts.init(container);
    chart.setOption({
        tooltip: {
            formatter: function (params) {
                if (!params.data.value) {
                    var lines = ['<b>' + escapeHtml(params.data.name) + '</b>'];
                    if (sizeHuman) lines.push(strings.size + ': ' + escapeHtml(sizeHuman));
                    lines.push(tableNames.length + ' ' + strings.records.toLowerCase());
                    return lines.join('<br>');
                }
                var t = params.data.value;
                return [
                    '<b>' + escapeHtml(params.data.name) + '</b>',
                    strings.records + ': ' + t.records,
                    strings.size + ': ' + escapeHtml(t.sizeHuman || strings.unknown),
                    strings.autoIncrement + ': ' + (t.hasAutoIncrementKey ? strings.yes : strings.no),
                    strings.updatedAt + ': ' + (t.hasUpdatedAt ? strings.yes : strings.no),
                    strings.syncEligible + ': ' + escapeHtml(strings[MODE_LABELS[t.syncMode]] || strings.modeNone),
                ].join('<br>');
            },
        },
        series: [{
            type: 'tree',
            data: [root],
            layout: 'orthogonal',
            orient: 'LR',
            symbol: 'circle',
            expandAndCollapse: false,
            label: {
                position: 'top',
                verticalAlign: 'middle',
                align: 'center',
                fontSize: 12,
            },
            leaves: {
                label: { position: 'right', verticalAlign: 'middle', align: 'left', fontSize: 12 },
            },
            lineStyle: { color: '#c1c9d2', width: 1.5, curveness: 0.4 },
            emphasis: { focus: 'ancestor' },
        }],
    });
    window.addEventListener('resize', function () { chart.resize(); });
})();
