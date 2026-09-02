<!doctype html>
<html lang="<?= esc(service('request')->getLocale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= lang('App.startTitle') ?></title>
<style>
  :root{color-scheme:light dark;--bg:#0f1115;--fg:#e8eaed;--muted:#9aa0a6;--acc:#4f8cff;--bad:#e05252;--good:#3ecf8e;}
  @media (prefers-color-scheme: light){:root{--bg:#f5f6f8;--fg:#1a1c20;--muted:#5f6368;}}
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
       background:var(--bg);color:var(--fg);font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}
  .box{width:min(420px,92vw);text-align:center}
  h1{font-size:16px;font-weight:600;margin:0 0 6px}
  .status{color:var(--muted);font-size:13px;min-height:18px;margin-bottom:18px}
  .spinner{width:28px;height:28px;margin:0 auto 16px;border:3px solid rgba(127,127,127,.25);
           border-top-color:var(--acc);border-radius:50%;animation:spin 0.8s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}
  .list{text-align:left;font-size:12px;color:var(--muted);margin-top:14px;border-top:1px solid rgba(127,127,127,.2);padding-top:10px}
  .row{display:flex;justify-content:space-between;padding:3px 0;font-family:ui-monospace,Consolas,monospace}
  .row .t{color:var(--fg)}
  .row.ok .t{color:var(--good)}
  .row.fail .t{color:var(--bad)}
  .offline{display:none}
  .offline.show{display:block}
  .live{display:block}
  .live.hide{display:none}
  button{background:var(--acc);color:#fff;border:0;border-radius:6px;padding:10px 16px;
         font-size:13px;cursor:pointer;margin:4px}
  button.ghost{background:transparent;border:1px solid var(--muted);color:var(--fg)}
  .manual{margin-top:12px;display:flex;gap:6px}
  .manual input{flex:1;padding:8px;border-radius:6px;border:1px solid var(--muted);
                background:transparent;color:var(--fg);font-size:13px}
</style>
</head>
<body>
  <div class="box">

    <div id="live" class="live">
      <div class="spinner" id="spinner"></div>
      <h1 id="title"><?= lang('App.startSearching') ?></h1>
      <div class="status" id="status"></div>
      <div class="list" id="list"></div>
    </div>

    <div id="offline" class="offline">
      <h1><?= lang('App.startNoneReachable') ?></h1>
      <div class="status"><?= lang('App.startAvailableOffline') ?></div>
      <button id="retry"><?= lang('App.startRetry') ?></button>
      <div class="manual">
        <input id="manualUrl" placeholder="https://exemplu.ro">
        <button id="manualGo" class="ghost"><?= lang('App.startConnect') ?></button>
      </div>
      <div class="list" id="listOffline"></div>
    </div>

  </div>
  <script src="<?= base_url('assets/node-picker.js') ?>"></script>
  <script>
  (function () {
    // Seeded server-side from RouteRegistrar::servers() (this node's own
    // baseURL plus every 'public' peer) - same source /server-list.json
    // itself reads from, so this page's very first paint already has the
    // right list with no extra round trip. localStorage only ever
    // REFINES that seed for a later visit (a node added/removed since),
    // exactly like the standalone C:\ai\server-picker's own seed/refresh
    // split.
    var SEED = <?= json_encode($servers, JSON_UNESCAPED_SLASHES) ?>;
    var HEALTH_PATH = '/healthz';
    var TIMEOUT_MS = 2500;
    var LS_LAST_GOOD = 'asyncron_start_last_good_v1';

    var $ = function (id) { return document.getElementById(id); };

    function getLastGood() {
        try { return JSON.parse(localStorage.getItem(LS_LAST_GOOD) || 'null'); } catch (e) { return null; }
    }
    function setLastGood(url) {
        try { localStorage.setItem(LS_LAST_GOOD, JSON.stringify({ url: url, ts: Date.now() })); } catch (e) {}
    }

    function renderRow(container, url, state, ms) {
        var row = container.querySelector('[data-url="' + CSS.escape(url) + '"]');
        if (!row) {
            row = document.createElement('div');
            row.className = 'row';
            row.dataset.url = url;
            row.innerHTML = '<span class="t"></span><span class="m"></span>';
            container.appendChild(row);
        }
        row.className = 'row ' + state;
        row.querySelector('.t').textContent = url.replace(/^https?:\/\//, '');
        row.querySelector('.m').textContent = state === 'ok' ? (ms + ' ms') : (state === 'fail' ? '<?= esc(lang('App.startUnavailable'), 'js') ?>' : '…');
    }

    function connect(url) {
        setLastGood(url);
        location.href = url.replace(/\/$/, '') + '/';
    }

    function showOffline() {
        $('live').classList.add('hide');
        $('offline').classList.add('show');
        var last = getLastGood();
        var listOffline = $('listOffline');
        $('list').querySelectorAll('.row').forEach(function (r) { listOffline.appendChild(r.cloneNode(true)); });
    }

    function run() {
        var listEl = $('list');
        listEl.innerHTML = '';
        SEED.forEach(function (url) { renderRow(listEl, url, 'pending'); });
        $('status').textContent = SEED.length + ' <?= esc(lang('App.startServersCount'), 'js') ?>';

        window.NodePicker.race(SEED, HEALTH_PATH, TIMEOUT_MS, function (url, state, ms) {
            renderRow(listEl, url, state, ms);
        }).then(
            function (winner) {
                $('status').textContent = winner.url.replace(/^https?:\/\//, '') + ' — ' + winner.ms + ' ms';
                setTimeout(function () { connect(winner.url); }, 300);
            },
            function () { showOffline(); }
        );
    }

    $('retry').addEventListener('click', function () {
        $('offline').classList.remove('show');
        $('live').classList.remove('hide');
        run();
    });
    $('manualGo').addEventListener('click', function () {
        var v = $('manualUrl').value.trim();
        if (v) { connect(v.indexOf('http') === 0 ? v : 'https://' + v); }
    });

    run();
  })();
  </script>
</body>
</html>
